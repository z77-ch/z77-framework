<?php

namespace Z77\Module\Creditor\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Posting\CreditorPostingVariant;
use Z77\Module\Creditor\Repositories\CreditorIntakeRepository;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Services\SaveRequest;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Module\Financial\Services\Actor;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * THE intake path of the Kreditorenjournal (creditor plan §3) — `receive()`, the model of
 * receipt's `ReceiptCaptureService::capture()`: no session needed, the actor is data. Fed by the
 * backend upload (stage 1b) and later by the folder job (stage 2).
 *
 *   1. **Sniff** the BYTES — never the client's name or type: not empty, at most
 *      {@see maxBytes()} (`creditorConfig['intake']['maxBytes']`, 20 MiB), the `%PDF-` header in the
 *      first KiB AND `finfo` on the bytes = `application/pdf`. Anything else is refused.
 *   2. **Idempotency** (owner answer 4): the SHA-256 of the bytes; a hash read before stores
 *      nothing and is refused as {@see CreditorRefusedException::DUPLICATE_FILE} with «bereits
 *      eingelesen am …» — the upload shows it as «identisch, schon vorhanden», not as an error.
 *      Deleting an UNPOSTED invoice frees its hash (the row goes); a discarded duplicate keeps it.
 *      `creditor_intake.sha256` is UNIQUE: two parallel first sends fail one of them at commit,
 *      answered the same way.
 *   3. **Store** the PDF unaltered in the DMS: module partition «Kreditorenrechnungen» (key
 *      {@see MODULE_KEY}, remappable by `dmsConfig['moduleFolders']`), a folder per fiscal year
 *      and month of RECEIPT (the invoice date is not known before it is read) — the trusted path
 *      `ModuleDrive::saveGenerated()`, which does not sniff (so step 1 does). The stored checksum is
 *      compared with the hash — a mismatch is an error, nothing is written.
 *   4. **Write** the intake row and the invoice (OPEN, unpaid, the `direct` variant) with ONE line
 *      in one unit of work. A creditor handed in (stage 1b: chosen by hand; stage 2: recognised)
 *      gives the line its default account and VAT decision and the invoice its «Bezahlt mit» —
 *      the creditor's defaults; a mandator not liable to VAT forces «ohne MWST» (owner answer 5).
 *
 * The invoice is written «Erkennung ausstehend» (`recognition_pending_at`, in the same unit of
 * work): the recognition (stage 2) runs ONLY in the job `creditor-intake`, never in a web request
 * (owner 2026-11-15, creditor.md CREDITOR-RECOG-WEB-001) — it reads the PDF back from the DMS and
 * fills only what is still empty. A caller in a request queues the job
 * ({@see \Z77\Module\Creditor\Jobs\CreditorIntakeJob::queue()}); no process is started here.
 */
final class CreditorIntakeService
{
    /** The DMS module key — a code constant (ADR-021 S2), never request input. */
    public const MODULE_KEY = 'creditor';
    /** The name the partition gets when this module creates it. */
    public const PARTITION_NAME = 'Kreditorenrechnungen';
    /** The fallback when the module config carries no limit (`creditorConfig['intake']['maxBytes']`). */
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    private ?DocumentService $documents;
    private ?string $actor;

    /**
     * @param DocumentService|null $documents null = `DocumentService::create()` at the first store
     * @param string|null $actor the receiver's name; null = the session identity at the first write
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?DocumentService $documents = null, ?string $actor = null)
    {
        $this->documents = $documents;
        $this->actor     = $actor !== null ? Actor::normalize($actor) : null;
    }

    /** The largest PDF the intake takes — the ONE place that reads the config. */
    public static function maxBytes(): int
    {
        try {
            $bytes = (int) (DI::getModuleManager()->getModuleConfig('creditor')?->get('intake', [])['maxBytes'] ?? 0);
        } catch (\Throwable) {
            $bytes = 0;   // no module manager wired (a script, a test) — the default
        }

        return $bytes > 0 ? $bytes : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Receive one supplier PDF into the Kreditorenjournal.
     *
     * @param string               $bytes        the file, raw
     * @param string               $originalName the client's (or the folder's) name — only its base name survives
     * @param string               $channel      {@see CreditorIntake::CHANNEL_UPLOAD} or `CHANNEL_FOLDER`
     * @param CreditorProfile|null $creditor     the creditor when known — the line starts on its defaults
     * @throws CreditorRefusedException EMPTY_FILE, TOO_LARGE, NOT_PDF, DUPLICATE_FILE — nothing stored
     */
    public function receive(string $bytes, string $originalName, string $channel = CreditorIntake::CHANNEL_UPLOAD, ?CreditorProfile $creditor = null): CreditorInvoice
    {
        if (!in_array($channel, CreditorIntake::CHANNELS, true)) {
            throw new \InvalidArgumentException("Unknown creditor intake channel '{$channel}'");
        }
        self::sniff($bytes);
        $sha256 = hash('sha256', $bytes);
        $known  = $this->intakes()->findBySha256($sha256);
        if ($known !== null) {
            throw $this->duplicateFile($known);
        }
        $actor = $this->actor();
        $now   = new \DateTimeImmutable();
        $name  = self::baseName($originalName);

        $documentId = $this->store($bytes, $name, $sha256, $now, $actor);
        $invoices   = new CreditorInvoiceService($this->em, $actor);

        try {
            return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($channel, $name, $bytes, $sha256, $documentId, $actor, $now, $creditor, $invoices): CreditorInvoice {
                $intake = new CreditorIntake($channel, $name, strlen($bytes), $sha256, $documentId, $actor, $now);
                $this->em->persist($intake);
                $invoice  = new CreditorInvoice($intake, $documentId, $actor, $now, $creditor, CreditorPostingVariant::current());
                $invoice->queueRecognition($now);
                $defaults = ['account' => null, 'taxCode' => null, 'withoutVat' => !$invoices->liableToVat(), 'taxOnly' => false];
                if ($creditor !== null) {
                    $payment = $invoices->paymentDefaults($creditor);
                    $invoice->applyPayment($payment['code'], $payment['credit']);
                    $defaults = $invoices->lineDefaults($creditor);
                }
                $this->em->persist($invoice);
                // A creditor that learned «nur Steuer» (S56-4) starts its line so — no account, z77 derives it.
                $this->em->persist(new CreditorInvoiceLine($invoice, 0, '', null, $defaults['taxOnly'] ? null : $defaults['account'], $defaults['taxCode'], $defaults['withoutVat'],
                    $defaults['taxOnly'] ? GrossPartKind::TaxOnly : GrossPartKind::Standard));

                return $invoice;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), CreditorIntake::UNIQUE_SHA256)) {
                throw $e;
            }
            // A parallel request read the same file first. The PDF stored above stays in the DMS
            // without a row — logged, the Drive shows it under «Kreditorenrechnungen».
            error_log('creditor intake: parallel duplicate of ' . $sha256 . ' — DMS document ' . $documentId . ' has no invoice');
            $known = $this->intakes()->findBySha256($sha256);

            throw $known !== null ? $this->duplicateFile($known) : new CreditorRefusedException(CreditorRefusedException::DUPLICATE_FILE, $name . ': bereits eingelesen.');
        }
    }

    /**
     * The intake's byte check — not empty, not above {@see maxBytes()}, a PDF by its header and its
     * sniffed type.
     *
     * @throws CreditorRefusedException EMPTY_FILE, TOO_LARGE, NOT_PDF
     */
    public static function sniff(string $bytes): void
    {
        if ($bytes === '') {
            throw new CreditorRefusedException(CreditorRefusedException::EMPTY_FILE, 'Die Datei ist leer.');
        }
        $max = self::maxBytes();
        if (strlen($bytes) > $max) {
            throw new CreditorRefusedException(CreditorRefusedException::TOO_LARGE, 'Die Datei ist grösser als ' . intdiv($max, 1024 * 1024) . ' MB.');
        }
        $info = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $info !== false ? (string) finfo_buffer($info, $bytes) : '';
        if (!str_contains(substr($bytes, 0, 1024), '%PDF-') || $mime !== 'application/pdf') {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_PDF, 'Kein PDF — nur PDF-Rechnungen werden eingelesen.');
        }
    }

    /** «rechnung-maerz.pdf» — the base name, slugged, at most 60 characters, always `.pdf`. */
    public static function baseName(string $clientName): string
    {
        // A file name, not a routing name: lower-cased first, or the slug splits «IMG» into «i-m-g».
        $base = Naming::toSlug(mb_strtolower(pathinfo(basename(str_replace('\\', '/', $clientName)), PATHINFO_FILENAME)));

        return ($base !== '' ? mb_substr($base, 0, 60) : 'rechnung') . '.pdf';
    }

    /** The PDF into `Kreditorenrechnungen/<fiscal year>/<month of receipt>`; returns the document id. */
    private function store(string $bytes, string $name, string $sha256, \DateTimeImmutable $now, string $actor): int
    {
        $year     = $this->em->getRepository(FiscalYear::class)->findByDate($now);
        $yearName = $year !== null ? str_replace('/', '-', $year->getCode()) : $now->format('Y');
        $drive    = ($this->documents ??= DocumentService::create())->forModule(self::MODULE_KEY, self::PARTITION_NAME);
        $folder   = $drive->folder($yearName, $now->format('m'));

        $document = $drive->saveGenerated($bytes, new SaveRequest(
            originalName: $name,
            mimeType: 'application/pdf',
            folderId: (int) $folder->getId(),
            displayName: mb_substr('Kreditorenrechnung ' . $now->format('Y-m-d') . ' ' . $name, 0, 120),
            meta: ['creditor' => ['received_by' => $actor, 'sha256' => $sha256]],
        ));
        if (!hash_equals($sha256, strtolower($document->getChecksum()))) {
            throw new \RuntimeException('creditor intake: the DMS stored other bytes than it was given (document ' . $document->getId() . ')');
        }

        return (int) $document->getId();
    }

    /**
     * The bytes of a supplier PDF this intake stored — read back from the module's OWN DMS partition
     * (`ModuleDrive::read()`), for the recognition in the job. Null = not readable there (gone, in
     * the trash, moved out of «Kreditorenrechnungen» in the Drive, bytes missing or altered).
     */
    public function storedPdf(int $documentId): ?string
    {
        return ($this->documents ??= DocumentService::create())->forModule(self::MODULE_KEY, self::PARTITION_NAME)->read($documentId);
    }

    /**
     * A deleted invoice's PDF into the Drive's TRASH (ADR-059 decision 9; owner 2026-11-20) — through
     * the module's own handle (`ModuleDrive::trash()`: the «Kreditorenrechnungen» partition only, no
     * session right needed, restorable, never a purge). Call it AFTER `CreditorInvoiceService::delete()`
     * committed and only when it answered `freed` — a DISCARDED duplicate keeps its PDF: its intake row
     * still names it, and the folder channel confirms a re-sent identical file against those bytes
     * (`CreditorIntakeFolder::duplicate()`) before it removes the file.
     *
     * @return bool true = in the trash now; false = not touched (unknown, or moved out of the partition)
     */
    public function trashPdf(int $documentId): bool
    {
        return ($this->documents ??= DocumentService::create())->forModule(self::MODULE_KEY, self::PARTITION_NAME)->trash($documentId);
    }

    /** «bereits eingelesen am 15.11.2026 (Rechnung 4711)» — the answer for a file read before. */
    private function duplicateFile(CreditorIntake $known): CreditorRefusedException
    {
        $invoice = $this->em->getRepository(CreditorInvoice::class)->findByIntake($known);
        $what    = match (true) {
            $invoice !== null      => $invoice->label(),
            $known->isDiscarded()  => 'als Doppel verworfen — das PDF liegt in den Dokumenten',
            default                => 'die Rechnung dazu gibt es nicht mehr, das PDF liegt in den Dokumenten',
        };

        return new CreditorRefusedException(
            CreditorRefusedException::DUPLICATE_FILE,
            'bereits eingelesen am ' . $known->getReceivedAt()->format('d.m.Y') . ' (' . $what . ')'
        );
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function intakes(): CreditorIntakeRepository
    {
        return $this->em->getRepository(CreditorIntake::class);
    }
}
