<?php

namespace Z77\Module\Creditor\Services;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader;
use Z77\Module\Creditor\Recognition\Ai\AiOutcome;
use Z77\Module\Creditor\Recognition\InvoiceFacts;
use Z77\Module\Creditor\Recognition\RecognitionTools;
use Z77\Module\Creditor\Recognition\TextLayerOutcome;
use Z77\Module\Creditor\Recognition\TextLayerRecognizer;
use Z77\Module\Creditor\Repositories\CreditorIbanRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The recognition of a received supplier PDF (creditor plan §3 step 5, §4, §5; owner answers §11.6,
 * §11.9) — run ONLY in the job `creditor-intake` (CLI), never in a web request (owner 2026-11-15,
 * creditor.md CREDITOR-RECOG-WEB-001: a shared host's web PHP may forbid `proc_open()`, and the AI
 * may take minutes): `CreditorIntakeService::receive()` marks the invoice «Erkennung ausstehend»
 * for both channels, {@see recognizePending()} works the marked invoices with the PDF read back from
 * the DMS. It reads, matches and hands the result to `CreditorInvoiceService::applyRecognition()`,
 * which fills only what is still EMPTY and clears the mark with the run's last step.
 *
 *   1. **Text layer** ({@see TextLayerRecognizer}, pdftotext — `creditorConfig['tools']['pdftotext']`):
 *      the QR payment part (IBAN, amount, reference, message, creditor, Swico S1), else at least a
 *      UID the letterhead prints. A host without pdftotext: the note «Texterkennung auf diesem
 *      Server nicht verfügbar» — the row is completed by hand, nothing blocked.
 *   2. **Matching** (plan §5): by IBAN through `CreditorIbanRepository::findProfilesByIban()` (a
 *      creditor has several IBANs); a SHARED IBAN (n creditors) is decided only by the UID or —
 *      exactly one hit — an IBAN link's hint found in the text, otherwise the candidates are
 *      listed and the owner chooses (never a guess); no IBAN hit → by UID; a UID naming another
 *      creditor than a single IBAN hit is a conflict (no pick). Unknown → the proposal.
 *   3. **AI** (opt-in, `creditorAi` in kibot's file, default OFF): only when the text layer did
 *      not resolve the invoice (no text, no payment part, the tool missing, or an unknown creditor
 *      without IBAN match) AND {@see \Z77\Module\Creditor\Recognition\Ai\AiSettings::enabled()}.
 *      Its values go the same way (only empty fields, the step «KI»); SKIPPED / FAILED is a note,
 *      the row is completed by hand. The category in words never becomes an account.
 *
 * Never throws for a PDF it cannot read; a database error propagates (the invoice was committed
 * before — it stays, unrecognised).
 */
final class CreditorRecognitionService
{
    public const NOTE_MANUAL = 'nicht erkannt — von Hand erfassen';
    /** The job gave a run up: the PDF is not readable in the module's DMS partition. */
    public const NOTE_NO_PDF = 'PDF in den Dokumenten nicht lesbar — von Hand erfassen';
    /** The job gave a run up: the recognition threw (logged). */
    public const NOTE_BROKEN = 'Erkennung abgebrochen — «Jetzt erkennen» versucht es nochmals';
    /**
     * Seconds of the slice that must be left to START a further AI step in the same slice (owner
     * 2026-11-15: «solange 30 s übrig sind» — one AI invoice per cron minute was too slow). The
     * first AI step of a slice only needs `hasTimeLeft()`, like every text step.
     */
    public const AI_MIN_SECONDS = 30;

    private ?TextLayerRecognizer $text;
    private ?AiInvoiceReader $ai;
    /** AI steps run in the current {@see recognizePending()} call; null = no such call runs (a direct {@see recognize()}: never gated). */
    private ?int $aiSteps = null;
    /** The current invoice's AI step was not started — too little time left; it stays pending for the next slice. */
    private bool $aiDeferred = false;
    /** @var (callable(): int)|null seconds left in the slice; null = no further AI step after the first */
    private $secondsLeft = null;

    /**
     * @param TextLayerRecognizer|null $text null = the installation's ({@see textRecognizer()})
     * @param AiInvoiceReader|null     $ai   null = the installation's (kibot's file)
     * @param string|null              $actor who received the file; null = the session identity
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?TextLayerRecognizer $text = null, ?AiInvoiceReader $ai = null, private readonly ?string $actor = null)
    {
        $this->text = $text;
        $this->ai   = $ai;
    }

    /**
     * The configured pdftotext — `creditorConfig['tools']['pdftotext']`, the ONE place it is read: a
     * binary path or name; 'auto', '' or absent = `pdftotext` searched in PATH by the system.
     */
    public static function pdftotext(): string
    {
        try {
            $value = DI::getModuleManager()->getModuleConfig('creditor')?->get('tools', [])['pdftotext'] ?? '';
        } catch (\Throwable) {
            $value = '';   // no module manager wired (a script, a test)
        }
        $value = is_string($value) ? trim($value) : '';

        return $value === '' || strtolower($value) === 'auto' ? RecognitionTools::DEFAULT_PDFTOTEXT : $value;
    }

    public static function textRecognizer(): TextLayerRecognizer
    {
        return new TextLayerRecognizer(RecognitionTools::probe(self::pdftotext()));
    }

    /**
     * The job's part (`creditor-intake`): recognise the invoices marked «Erkennung ausstehend»,
     * the oldest mark first, each with the PDF read back from the module's DMS partition.
     *
     *   - `$hasTimeLeft()` is asked before each invoice — no time: `more`;
     *   - the FIRST AI step of a call needs only that; a further one starts only while
     *     `$secondsLeft()` ≥ {@see AI_MIN_SECONDS} (owner 2026-11-15) — otherwise that invoice is
     *     left untouched (nothing written, still marked) and the call ends with `more`. Without
     *     `$secondsLeft` a call runs at most ONE AI step. An AI call may take
     *     `creditorAi.timeoutSeconds` (120 s), longer than the runner's slice budget: that only
     *     delays the next pass — the runner's per-job lock never lets two passes overlap;
     *   - a PDF that cannot be read back, or a recognition that throws, ends the run with a note
     *     ({@see NOTE_NO_PDF}, {@see NOTE_BROKEN}) — the mark goes, so a broken invoice does not
     *     come back every minute (and never calls the AI again by itself); «Jetzt erkennen»
     *     marks it again. A database that is away leaves the mark: the next pass tries again.
     *
     * @param callable(): bool                $hasTimeLeft
     * @param (callable(int): ?string)|null   $pdfOf document id → the PDF bytes; null = {@see CreditorIntakeService::storedPdf()}
     * @param (callable(): int)|null          $secondsLeft seconds left in the slice (`JobContext::getSecondsLeft()`); null = one AI step per call
     * @return array{recognized: int, failed: int, more: bool, notes: list<string>}
     */
    public function recognizePending(callable $hasTimeLeft, ?callable $pdfOf = null, ?callable $secondsLeft = null): array
    {
        try {
            return $this->workPending($hasTimeLeft, $pdfOf, $secondsLeft);
        } finally {
            $this->aiSteps = null;
        }
    }

    /** {@see recognizePending()}'s loop — the slice's AI count lives only while it runs. */
    private function workPending(callable $hasTimeLeft, ?callable $pdfOf, ?callable $secondsLeft): array
    {
        $result = ['recognized' => 0, 'failed' => 0, 'more' => false, 'notes' => []];
        $this->aiSteps     = 0;
        $this->secondsLeft = $secondsLeft;
        $pdfOf ??= fn(int $documentId): ?string => (new CreditorIntakeService($this->em, null, $this->actor))->storedPdf($documentId);
        /** @var \Z77\Module\Creditor\Repositories\CreditorInvoiceRepository $rows */
        $rows = $this->em->getRepository(CreditorInvoice::class);
        $seen = [];
        while (($id = $rows->nextPendingRecognition($seen)) !== null) {
            if (!$hasTimeLeft()) {
                $result['more'] = true;

                return $result;
            }
            $seen[]           = $id;
            $this->aiDeferred = false;
            $invoices         = new CreditorInvoiceService($this->em, $this->actor);
            try {
                $invoice = $invoices->find($id);
                $bytes   = $invoice !== null ? $pdfOf($invoice->getDocumentId()) : null;
                if ($invoice !== null && $bytes === null) {
                    // null = the invoice is gone meanwhile (deleted — its PDF went to the trash):
                    // nothing to report, the run is simply over.
                    if ($invoices->endRecognition($id, self::NOTE_NO_PDF) !== null) {
                        $result['failed']++;
                        $result['notes'][] = 'Rechnung ' . $id . ': ' . self::NOTE_NO_PDF;
                    }
                } elseif ($invoice !== null) {
                    $done = $this->recognize($id, $bytes);
                    if ($this->aiDeferred) {
                        $result['more'] = true;   // its AI step goes to the next slice

                        return $result;
                    }
                    if ($done !== null) {
                        $result['recognized']++;   // null = deleted or posted meanwhile: the result was dropped
                    }
                }
            } catch (\Throwable $e) {
                error_log('creditor recognition: invoice ' . $id . ' — ' . get_class($e) . ': ' . $e->getMessage());
                $result['failed']++;
                $result['notes'][] = 'Rechnung ' . $id . ': Erkennung abgebrochen (' . get_class($e) . ')';
                try {
                    $invoices->endRecognition($id, self::NOTE_BROKEN);
                } catch (\Throwable) {
                    // the database is away — the mark stays, the next pass tries again
                }
            }
        }

        return $result;
    }

    /**
     * Recognise invoice $invoiceId from the bytes of its PDF — called by {@see recognizePending()}
     * (the job); a harness calls it directly. Starts processes (pdftotext) and may call the AI, so
     * it MUST NOT run in a web request. Returns the invoice as it stands afterwards (null when it is
     * gone or posted meanwhile); the pending mark is cleared with the run's last step.
     */
    public function recognize(int $invoiceId, string $pdfBytes): ?CreditorInvoice
    {
        $invoices = new CreditorInvoiceService($this->em, $this->actor);
        $current  = $invoices->find($invoiceId);
        if ($current === null || !$current->isEditable()) {
            return null;
        }
        $outcome = ($this->text ??= self::textRecognizer())->recognize($pdfBytes);
        $facts   = match (true) {
            $outcome->isRecognized() => InvoiceFacts::fromPayment($outcome->payment, $outcome->text),
            $outcome->text !== null  => InvoiceFacts::fromTextOnly($outcome->text),
            default                  => null,
        };
        $match = $this->match($facts);

        // Resolved = a payment part was read AND the creditor is known, matched, or a shared IBAN's
        // candidates are listed (the owner's choice — the AI cannot decide it either).
        $resolved = $outcome->isRecognized() && ($current->getCreditor() !== null || $match['creditor'] !== null || $match['candidates'] !== []);
        $reader   = $resolved ? null : ($this->ai ??= AiInvoiceReader::forInstallation());
        $aiOn     = $reader !== null && $reader->settings()->enabled();
        if ($aiOn && !$this->mayStartAi()) {
            // Too little time left in the job's slice for a further AI step: nothing is written,
            // the mark stays — the next slice reads it again from the start.
            $this->aiDeferred = true;

            return $current;
        }

        $notes = [];
        if ($outcome->state === TextLayerOutcome::UNAVAILABLE) {
            $notes[] = 'Texterkennung auf diesem Server nicht verfügbar';
        }
        if ($facts?->notForPayment) {
            $notes[] = 'Zahlteil «Nicht zur Zahlung verwenden» — prüfen, ob die Rechnung schon bezahlt ist';
        }
        if (!$aiOn && ($facts === null || !$facts->hasAnyValue())) {
            $notes[] = self::NOTE_MANUAL;
        }
        $step = array_filter([
            'state'      => $outcome->state,
            'reason'     => $outcome->reason,
            'confidence' => $facts?->confidence,
        ], static fn($v) => $v !== null);
        $invoice = $invoices->applyRecognition($invoiceId, InvoiceFacts::STEP_TEXT, $step, $facts, $match['creditor'], $match['by'], $match['candidates'], $notes, !$aiOn);
        if ($invoice === null || !$aiOn) {
            return $invoice;
        }

        return $this->aiStep($invoices, $reader, $invoice, $pdfBytes, $outcome->text);
    }

    /**
     * Whether an AI step may start now: a direct {@see recognize()} and the first of a
     * {@see recognizePending()} call always, a further one only while {@see AI_MIN_SECONDS} are left.
     */
    private function mayStartAi(): bool
    {
        if ($this->aiSteps === null || $this->aiSteps === 0) {
            return true;
        }

        return $this->secondsLeft !== null && ($this->secondsLeft)() >= self::AI_MIN_SECONDS;
    }

    /**
     * The AI step (switched on) — its values only into empty fields; SKIPPED / FAILED leave a note,
     * the row is done by hand. Null when the invoice is gone or posted: an invoice deleted after
     * its text step is not sent to the AI at all (owner 2026-11-16, bulk «Löschen …» — no cost for
     * a deleted invoice), one deleted DURING the call drops the answer ({@see CreditorInvoiceService::applyRecognition()}
     * finds no row).
     */
    private function aiStep(CreditorInvoiceService $invoices, AiInvoiceReader $reader, CreditorInvoice $invoice, string $pdfBytes, ?string $text): ?CreditorInvoice
    {
        $id = (int) $invoice->getId();
        /** @var \Z77\Module\Creditor\Repositories\CreditorInvoiceRepository $rows */
        $rows = $this->em->getRepository(CreditorInvoice::class);
        if (!$rows->isInJournal($id)) {
            return null;
        }
        if ($this->aiSteps !== null) {
            $this->aiSteps++;
        }
        $match   = (string) ($invoice->recognition()['match'] ?? 'none');
        $outcome = $reader->read($pdfBytes, $text, 'creditor-invoice:' . $id);
        if (!$outcome->isRecognized() || $outcome->data === null || !$outcome->data->hasAnyValue()) {
            $reason = $outcome->reason ?? AiOutcome::MANUAL;

            return $invoices->applyRecognition($id, InvoiceFacts::STEP_AI, ['state' => $outcome->state, 'reason' => $reason], null, null, $match, (array) ($invoice->recognition()['candidates'] ?? []), [$reason]);
        }
        $facts = InvoiceFacts::fromAi($outcome->data);
        $found = $this->match($facts);

        return $invoices->applyRecognition(
            $id,
            InvoiceFacts::STEP_AI,
            ['state' => $outcome->state, 'model' => $outcome->data->model, 'confidence' => $facts->confidence],
            $facts,
            $found['creditor'],
            $found['by'] === 'none' ? $match : $found['by'],
            $found['candidates'],
        );
    }

    /**
     * The creditor behind the recognised values (plan §5) — never a guess.
     *
     * @return array{creditor: ?CreditorProfile, by: string, candidates: list<array{id: int, name: string}>}
     */
    public function match(?InvoiceFacts $facts): array
    {
        $none = ['creditor' => null, 'by' => 'none', 'candidates' => []];
        if ($facts === null) {
            return $none;
        }
        /** @var CreditorIbanRepository $ibans */
        $ibans = $this->em->getRepository(CreditorIban::class);
        /** @var CreditorProfileRepository $profiles */
        $profiles = $this->em->getRepository(CreditorProfile::class);
        $byUid    = $facts->uid !== null ? $profiles->findByUid($facts->uid) : null;
        if ($byUid !== null && !$byUid->isActive()) {
            $byUid = null;
        }
        $hits = $facts->iban !== null ? $ibans->findProfilesByIban($facts->iban) : [];

        if (count($hits) === 1) {
            if ($byUid !== null && $byUid->getId() !== $hits[0]->getId()) {
                return ['creditor' => null, 'by' => 'conflict', 'candidates' => self::candidates([$hits[0], $byUid])];
            }

            return ['creditor' => $hits[0], 'by' => 'iban', 'candidates' => []];
        }
        if (count($hits) > 1) {
            foreach ($hits as $hit) {
                if ($byUid !== null && $hit->getId() === $byUid->getId()) {
                    return ['creditor' => $hit, 'by' => 'uid', 'candidates' => []];
                }
            }
            $byHint = [];
            $text   = mb_strtolower((string) $facts->text);
            foreach ($hits as $hit) {
                foreach ($ibans->forProfile($hit) as $link) {
                    if ($link->getIban() === $facts->iban && $link->isActive() && $link->getHint() !== '' && $text !== '' && str_contains($text, mb_strtolower($link->getHint()))) {
                        $byHint[(int) $hit->getId()] = $hit;
                    }
                }
            }
            if (count($byHint) === 1) {
                return ['creditor' => array_values($byHint)[0], 'by' => 'hint', 'candidates' => []];
            }

            return ['creditor' => null, 'by' => 'shared', 'candidates' => self::candidates($hits)];
        }
        if ($byUid !== null) {
            return ['creditor' => $byUid, 'by' => 'uid', 'candidates' => []];
        }

        return $none;
    }

    /**
     * @param list<CreditorProfile> $profiles
     * @return list<array{id: int, name: string}>
     */
    private static function candidates(array $profiles): array
    {
        return array_map(static fn(CreditorProfile $p) => ['id' => (int) $p->getId(), 'name' => (string) $p->getContact()?->displayName()], $profiles);
    }
}
