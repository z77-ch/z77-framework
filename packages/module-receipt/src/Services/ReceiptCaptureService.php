<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Services\SaveRequest;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * THE capture path of the receipt journal (owner decisions E1–E8, plan T2 / T4, 2026-11-12): a
 * receipt arrives with its photo, an amount and optionally a text and a template, and lands in the
 * journal as `captured`. NOTHING is posted — the ledger sees the receipt only at «Journal
 * verbuchen» in the backend (`ReceiptJournalPostingService`).
 *
 * Callable WITHOUT a session (the gateway's device channel is stateless, T1): who captured it is
 * DATA the caller hands in (`capturedBy`, `deviceId`, `deviceName` — from the device principal),
 * never read from an `AuthService`. The photo goes through the DMS's trusted system path
 * (`DocumentService::forModule()` → `ModuleDrive::saveGenerated()`, T2), which does not sniff —
 * so this service checks the BYTES itself: the type by `finfo` on the bytes (never the client's
 * name or MIME), only {@see PHOTO_TYPES}, at most {@see MAX_PHOTO_BYTES}. The file name stored is
 * built here from the sniffed type.
 *
 * Where the photo goes (E4): the module partition «Belege» (key {@see MODULE_KEY}, remappable by
 * `dmsConfig['moduleFolders']`), a folder per FISCAL YEAR (the code of the year that covers the
 * receipt date; the calendar year when none does yet), in it a folder per month — `Belege/2026/11`.
 *
 * What a capture applies: the date (default today, E6; never in the future), the amount (required,
 * E5), the text (default: the template's label), the template's debit account and VAT decision
 * (E2), the payment method's credit account («Bezahlt mit», E10) — a template or payment method is
 * applied also when it was deactivated since (an offline queue, E7, may send it late); an unknown
 * code is refused.
 *
 * Idempotency (E7, the phone's offline queue): a `captureKey` (the client's idempotency key, `[A-Za-z0-9._:-]`, at most
 * 64) that already exists answers with THAT receipt and stores nothing — the same key means the
 * same capture. Unique in the schema; two parallel first sends fail one of them at commit.
 */
final class ReceiptCaptureService
{
    /** The DMS module key — a code constant (ADR-021 S2), never request input. */
    public const MODULE_KEY = 'receipt';
    /** The name the partition gets when this module creates it. */
    public const PARTITION_NAME = 'Belege';
    /** The largest photo / PDF a capture takes (the phone scales to ~1600 px JPEG before sending, plan B3). */
    public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
    /** Sniffed type → the extension the stored file gets. */
    public const PHOTO_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/pdf' => 'pdf',
    ];

    private ?DocumentService $documents;

    /** @param DocumentService|null $documents null = `DocumentService::create()` at the first store */
    public function __construct(private readonly UnifiedEntityManager $em, ?DocumentService $documents = null)
    {
        $this->documents = $documents;
    }

    /**
     * Capture one receipt into the journal.
     *
     * @param string      $amount       the gross amount as typed («45.50», «1'200,00») — required
     * @param string      $photoBytes   the photo or PDF, raw bytes
     * @param string      $photoName    the client's file name — only its base name survives, the extension comes from the sniffed type
     * @param string      $capturedBy   the backend user the device belongs to (E8) — required
     * @param string|null $date         `YYYY-MM-DD`; null or '' = today (E6)
     * @param string      $text         the posting text; '' = the template's label, or empty (completed in the journal)
     * @param string|null $templateCode a receipt template's code; null or '' = none (E2 fallback)
     * @param string|null $paymentMethodCode a payment method's code («Bezahlt mit», E10); null or '' = none (the owner sets the credit account)
     * @param string|null $deviceId    the gateway device's id; null = not captured through a device
     * @param string|null $deviceName   the device's name at capture time (snapshot for the journal)
     * @param string|null $captureKey   the client's idempotency key; a known key returns the existing receipt
     * @throws InvalidReceiptException field → German message; nothing stored
     */
    public function capture(
        string $amount,
        string $photoBytes,
        string $photoName,
        string $capturedBy,
        ?string $date = null,
        string $text = '',
        ?string $templateCode = null,
        ?string $paymentMethodCode = null,
        ?string $deviceId = null,
        ?string $deviceName = null,
        ?string $captureKey = null,
    ): Receipt {
        $errors = [];

        $captureKey = $captureKey !== null ? trim($captureKey) : null;
        if ($captureKey === '') {
            $captureKey = null;
        }
        if ($captureKey !== null) {
            if (mb_strlen($captureKey) > Receipt::CAPTURE_KEY_LENGTH || !preg_match('/^[A-Za-z0-9._:-]+$/', $captureKey)) {
                throw new InvalidReceiptException(['capture_key' => 'Ungültiger Wiederholungsschlüssel (höchstens ' . Receipt::CAPTURE_KEY_LENGTH . ' Zeichen: Buchstaben, Ziffern, . _ : -).']);
            }
            $existing = $this->em->getRepository(Receipt::class)->findByCaptureKey($captureKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $fields   = new ReceiptFields($this->em);
        $now      = new \DateTimeImmutable();
        $dateText = $date !== null && trim($date) !== '' ? $date : $now->format('Y-m-d');
        $day      = $fields->date($dateText, 'date', $errors, $now);
        $money    = $fields->amount($amount, 'amount', $errors);
        $text     = $fields->text($text, 'text', $errors);

        $capturedBy = trim($capturedBy);
        if ($capturedBy === '') {
            $errors['captured_by'] = 'Wer den Beleg erfasst hat, fehlt.';
        } elseif (mb_strlen($capturedBy) > Receipt::ACTOR_LENGTH) {
            $errors['captured_by'] = 'Der Name ist länger als ' . Receipt::ACTOR_LENGTH . ' Zeichen.';
        }
        $deviceId   = self::optional($deviceId);
        $deviceName = self::optional($deviceName);
        if (($deviceId !== null && mb_strlen($deviceId) > Receipt::DEVICE_ID_LENGTH) || ($deviceName !== null && mb_strlen($deviceName) > Receipt::DEVICE_NAME_LENGTH)) {
            $errors['device'] = 'Geräte-Angabe zu lang.';
        }

        $template = null;
        $templateCode = self::optional($templateCode);
        if ($templateCode !== null) {
            $template = $this->em->getRepository(ReceiptTemplate::class)->findByCode($templateCode);
            if ($template === null) {
                $errors['template'] = 'Die Vorlage «' . $templateCode . '» gibt es nicht.';
            }
        }
        $method = null;
        $paymentMethodCode = self::optional($paymentMethodCode);
        if ($paymentMethodCode !== null) {
            $method = $this->em->getRepository(PaymentMethod::class)->findByCode($paymentMethodCode);
            if ($method === null) {
                $errors['payment_method'] = 'Die Zahlungsart «' . $paymentMethodCode . '» gibt es nicht.';
            }
        }

        $mime = self::sniff($photoBytes, $errors);

        if ($errors !== [] || $day === null || $money === null || $mime === null) {
            throw new InvalidReceiptException($errors);
        }
        if ($text === '' && $template !== null) {
            $text = mb_substr($template->getLabel(), 0, Receipt::TEXT_LENGTH);
        }

        $documentId = $this->storePhoto($photoBytes, $photoName, $mime, $day, $text, $template, $capturedBy);

        return $this->em->getTransaction(Receipt::class)->run(function () use ($day, $money, $text, $documentId, $capturedBy, $now, $deviceId, $deviceName, $captureKey, $template, $method): Receipt {
            $receipt = new Receipt($day, $money, $text, $documentId, $capturedBy, $now, $deviceId, $deviceName, $captureKey);
            if ($template !== null) {
                $receipt->applyTemplate($template);
            }
            if ($method !== null) {
                $receipt->applyPaymentMethod($method);
            }
            $this->em->persist($receipt);

            return $receipt;
        });
    }

    /**
     * The sniffed type of $bytes when it is an allowed photo — else null with the field error set.
     *
     * @param array<string, string> $errors
     */
    public static function sniff(string $bytes, array &$errors): ?string
    {
        if ($bytes === '') {
            $errors['photo'] = 'Das Foto fehlt.';

            return null;
        }
        if (strlen($bytes) > self::MAX_PHOTO_BYTES) {
            $errors['photo'] = 'Das Foto ist grösser als ' . intdiv(self::MAX_PHOTO_BYTES, 1024 * 1024) . ' MB.';

            return null;
        }
        $info = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $info !== false ? (string) finfo_buffer($info, $bytes) : '';
        if (!isset(self::PHOTO_TYPES[$mime])) {
            $errors['photo'] = 'Nur ein Foto (JPEG, PNG) oder ein PDF.';

            return null;
        }

        return $mime;
    }

    /** The photo into `Belege/<fiscal year>/<month>`; returns the document id. */
    private function storePhoto(string $bytes, string $clientName, string $mime, \DateTimeImmutable $day, string $text, ?ReceiptTemplate $template, string $capturedBy): int
    {
        $year     = $this->em->getRepository(FiscalYear::class)->findByDate($day);
        $yearName = $year !== null ? str_replace('/', '-', $year->getCode()) : $day->format('Y');
        $drive    = ($this->documents ??= DocumentService::create())->forModule(self::MODULE_KEY, self::PARTITION_NAME);
        $folder   = $drive->folder($yearName, $day->format('m'));

        // A file name, not a routing name: lower-cased first, or the slug splits «IMG» into «i-m-g».
        $base  = Naming::toSlug(mb_strtolower(pathinfo(basename(str_replace('\\', '/', $clientName)), PATHINFO_FILENAME)));
        $name  = ($base !== '' ? mb_substr($base, 0, 60) : 'beleg') . '.' . self::PHOTO_TYPES[$mime];
        $label = trim('Beleg ' . $day->format('Y-m-d') . ' ' . ($text !== '' ? $text : ($template?->getLabel() ?? '')));

        $document = $drive->saveGenerated($bytes, new SaveRequest(
            originalName: $name,
            mimeType: $mime,
            folderId: (int) $folder->getId(),
            displayName: mb_substr($label, 0, 120),
            meta: ['receipt' => ['captured_by' => $capturedBy]],
        ));

        return (int) $document->getId();
    }

    private static function optional(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
