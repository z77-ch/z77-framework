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
 * §11.9) — run right after `CreditorIntakeService::receive()` by both channels (the upload and the
 * folder job `creditor-intake`). It reads, matches and hands the result to
 * `CreditorInvoiceService::applyRecognition()`, which fills only what is still EMPTY.
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

    private ?TextLayerRecognizer $text;
    private ?AiInvoiceReader $ai;

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
     * Recognise invoice $invoiceId from the bytes of its PDF. Returns the invoice as it stands
     * afterwards (null when it is gone or posted meanwhile).
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
        $invoice = $invoices->applyRecognition($invoiceId, InvoiceFacts::STEP_TEXT, $step, $facts, $match['creditor'], $match['by'], $match['candidates'], $notes);
        if ($invoice === null || !$aiOn) {
            return $invoice;
        }

        return $this->aiStep($invoices, $reader, $invoice, $pdfBytes, $outcome->text);
    }

    /** The AI step (switched on) — its values only into empty fields; SKIPPED / FAILED leave a note, the row is done by hand. */
    private function aiStep(CreditorInvoiceService $invoices, AiInvoiceReader $reader, CreditorInvoice $invoice, string $pdfBytes, ?string $text): CreditorInvoice
    {
        $id      = (int) $invoice->getId();
        $match   = (string) ($invoice->recognition()['match'] ?? 'none');
        $outcome = $reader->read($pdfBytes, $text, 'creditor-invoice:' . $id);
        if (!$outcome->isRecognized() || $outcome->data === null || !$outcome->data->hasAnyValue()) {
            $reason = $outcome->reason ?? AiOutcome::MANUAL;

            return $invoices->applyRecognition($id, InvoiceFacts::STEP_AI, ['state' => $outcome->state, 'reason' => $reason], null, null, $match, (array) ($invoice->recognition()['candidates'] ?? []), [$reason]) ?? $invoice;
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
        ) ?? $invoice;
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
