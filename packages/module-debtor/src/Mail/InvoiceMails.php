<?php

namespace Z77\Module\Debtor\Mail;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Services\Creditor;
use Z77\Module\Mailing\Outbox\OutboxDraft;
use Z77\Module\Mailing\Outbox\OutboxSettings;
use Z77\Module\Mailing\Ui\OutboxListing;
use Z77\Module\Mailing\Outbox\OutboxService;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\DocumentMailKinds;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\SenderIdentity;
use Z77\Shared\Money\AmountFormat;

/**
 * Booked documents by e-mail (step 3 of the mail plan, owner 2026-10-22) —
 * the ONE place that turns a final {@see Invoice} into an outbox mail:
 *
 *   - the RECIPIENT is the e-mail of the billing address ON THE DOCUMENT
 *     (`addr_email`, the snapshot rule — what the document was issued to);
 *     none, or not an address → the document is SKIPPED, not failed;
 *   - the SUBJECT and TEXT are the document mail kind's
 *     (`EmailService::documentMail()` — Service › E-Mail › Beleg-Mails, the
 *     module's declaration as the seed): `invoice` for an invoice,
 *     `credit-note` for a credit note; a fee has no kind yet (skipped);
 *   - the LANGUAGE is the document's (snapshot), German when the kind has no
 *     text in it;
 *   - the SENDER ACCOUNT is the installation's (the outbox leaves the From
 *     address to the `Mailer`); the PERSON is the staff member's chosen sender
 *     identity (owner 2026-10-23, `SenderIdentities`): his display name on the
 *     From, «name <email>» as Reply-To, `{anzeigename}` in the text. The former
 *     Reply-To = the mandator's e-mail is gone;
 *   - the ATTACHMENT is the reference `invoice-pdf:<id>@<revision>` — the
 *     archived PDF of exactly the version the mail was queued with, also when
 *     a revision follows before it is sent ({@see InvoicePdfAttachment},
 *     ADR-054 decision 21); the SOURCE is `invoice:<id>`, how the result list
 *     and the document's mask find what went out ({@see deliveries()});
 *   - a REPLACEMENT (a document that replaced another, ADR-054 decision 23)
 *     is mailed with its own kind `invoice-replacement` («ersetzt Rechnung
 *     A», owner decision O8), the replaced number, stamp and date as
 *     placeholders; a replaced or deleted document's WAITING mails are
 *     cancelled ({@see cancelWaiting()}); «covered» is judged per version —
 *     a mail of an earlier revision does not cover the current one.
 *
 * module-mailing is OPTIONAL for debtor (`composer.json` suggest, the
 * financial pattern): {@see available()} says whether the outbox is there;
 * nothing of module-mailing is touched while it is not.
 */
final class InvoiceMails
{
    public const MODULE = 'mailing';

    public const SOURCE_PREFIX = 'invoice:';

    /**
     * The delivery states that COVER a document (owner 2026-10-23): a mail waiting or sent —
     * «Alle per E-Mail senden …» leaves it out. A failed or cancelled mail counts as not sent.
     * The values of `OutboxMail::STATE_*`, written out: module-mailing is optional, and a constant
     * expression naming its class would load it.
     */
    public const COVERING_STATES = ['queued', 'sending', 'sent'];

    /** Of {@see COVERING_STATES}, the ones still waiting. */
    private const WAITING_STATES = ['queued', 'sending'];

    /** Document kind → document mail kind; a kind not listed is not mailed (yet). */
    private const MAIL_KINDS = [
        InvoiceKind::Invoice->value    => 'invoice',
        InvoiceKind::CreditNote->value => 'credit-note',
    ];

    /** The mail kind of a document that REPLACED another (ADR-054 decision 23, owner decision O8). */
    public const REPLACEMENT_KIND = 'invoice-replacement';

    public function __construct(
        private UnifiedEntityManager $em,
        private ?EmailService $emailService = null,
        private ?DocumentMailKinds $kinds = null,
    ) {
    }

    /** Whether the outbox (module-mailing) is installed and registered. */
    public function available(): bool
    {
        return class_exists(OutboxService::class) && DI::getModuleManager()->getModuleConfig(self::MODULE) !== null;
    }

    public static function source(Invoice $document): string
    {
        return self::SOURCE_PREFIX . $document->getId();
    }

    /** The document mail kind of $document, null when its kind is not mailed. */
    public static function mailKindOf(Invoice $document): ?string
    {
        return self::MAIL_KINDS[$document->kind()->value] ?? null;
    }

    /** The billing e-mail of the document's snapshot when it is an address, else ''. */
    public static function recipientOf(Invoice $document): string
    {
        $email = trim($document->getAddress()->getEmail());

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /**
     * Why $document gets no mail — German, null when it can be mailed: not issued (a draft, a void one), a kind
     * without a document mail, no (valid) address on the document.
     */
    public static function skipReason(Invoice $document): ?string
    {
        return match (true) {
            !$document->isIssued()                  => 'nicht fakturiert',
            self::mailKindOf($document) === null    => 'für ' . $document->kind()->label() . ' gibt es keine Beleg-Mail',
            self::recipientOf($document) === ''     => trim($document->getAddress()->getEmail()) === '' ? 'keine E-Mail-Adresse' : 'E-Mail-Adresse ungültig',
            default                                 => null,
        };
    }

    /**
     * The placeholders of a document mail — data, filled into subject and text by the kind's
     * resolution (the template escapes them).
     *
     * @return array<string, string>
     */
    public function values(Invoice $document, ?SenderIdentity $sender = null): array
    {
        $replaced = $this->predecessorOf($document);

        return [
            'nummer'   => (string) $document->getNumber(),
            'kunde'    => self::customerOf($document),
            'betrag'   => $document->getCurrency() . ' ' . AmountFormat::of($document->getGrossTotal()),
            'datum'    => $document->getInvoiceDate()->format('d.m.Y'),
            'faellig'  => $document->kind()->isPayable() ? $document->getDueDate()->format('d.m.Y') : '',
            'absender' => Creditor::mandator($this->em)?->getName() ?? '',
            // The display name of the identity chosen under «Senden als» (owner 2026-10-23);
            // '' without one — a line holding only it then disappears (documentMail()).
            'anzeigename' => $sender?->name ?? '',
            // The replaced document (ADR-054 decision 23) — '' unless this one replaced another.
            'ersetzt'       => $replaced !== null ? (string) $replaced->getNumber() : '',
            'ersetzt_id'    => (string) $replaced?->getDocumentStamp(),
            'ersetzt_datum' => $replaced !== null ? $replaced->getInvoiceDate()->format('d.m.Y') : '',
        ];
    }

    /**
     * The mail kind $document is sent with: `invoice-replacement` for an invoice that REPLACED another
     * (owner decision O8), else its kind's ({@see mailKindOf()}); null when it is not mailed.
     */
    public function mailKindFor(Invoice $document): ?string
    {
        $kind = self::mailKindOf($document);

        return $kind === 'invoice' && $this->predecessorOf($document) !== null ? self::REPLACEMENT_KIND : $kind;
    }

    /** The attachment reference of $document's CURRENT version — `invoice-pdf:<id>@<revision>` (ADR-054 decision 21). */
    public static function attachmentOf(Invoice $document): string
    {
        return InvoicePdfAttachment::TYPE . ':' . $document->getId() . '@' . $document->getRevision();
    }

    /**
     * Cancels the WAITING (`queued`) outbox mails of $document — a replaced or deleted document is never
     * sent (ADR-054 decision 21); a mail already sending or sent stays as it is (history). Runs in the
     * caller's unit of work (`InvoicingService`, the replacement / deletion). Answers how many.
     */
    public function cancelWaiting(Invoice $document): int
    {
        if (!$this->available() || $document->getId() === null) {
            return 0;
        }
        $outbox    = new OutboxService($this->em);
        $cancelled = 0;
        foreach ($this->deliveries([(int) $document->getId()])[(int) $document->getId()] ?? [] as $row) {
            if ($row['state'] !== 'queued') {
                continue;
            }
            try {
                $outbox->cancel((int) $row['id']);
                $cancelled++;
            } catch (\Z77\Module\Mailing\Outbox\OutboxStateException) {
                // claimed by the job in between — it sends what it was queued with
            }
        }

        return $cancelled;
    }

    private function predecessorOf(Invoice $document): ?Invoice
    {
        if ($document->isDraft() || $document->getId() === null) {
            return null;
        }
        /** @var \Z77\Module\Debtor\Repositories\InvoiceRepository $invoices */
        $invoices = $this->em->getRepository(Invoice::class);

        return $invoices->predecessorOf($document);
    }

    /**
     * The mail of $document as the outbox will store it (subject and text resolved, the
     * language chosen) — the confirmation page shows the subject, the queue builds the draft.
     *
     * @return array{to: string, subject: string, body: string, language: string, kind: string}
     * @throws \LogicException $document cannot be mailed ({@see skipReason()})
     */
    public function compose(Invoice $document, ?SenderIdentity $sender = null): array
    {
        if (($reason = self::skipReason($document)) !== null) {
            throw new \LogicException($document->documentName() . ': ' . $reason);
        }
        $kind = (string) $this->mailKindFor($document);
        $mail = $this->emailService()->documentMail($kind, $document->getLanguage(), $this->values($document, $sender), $this->kinds());
        // A placeholder without a value (no mandator name) must not leave a dangling dash.
        $mail['subject'] = (string) preg_replace('/[\s—–·:-]+$/u', '', $mail['subject']);

        return ['to' => self::recipientOf($document), 'kind' => $kind] + $mail;
    }

    /**
     * Queues one outbox mail per mailable document of $documents into ONE batch
     * (`OutboxService::queueBatch()` — all or none). Documents without an address (or of a
     * kind without a mail) are SKIPPED and returned as such; nothing is queued when none is
     * left. The documents' lines need not be loaded — the PDF is rendered at send time.
     *
     * Every mail is signed by `$sender` — the identity the staff member chose under «Senden
     * als», already re-checked against HIS identities by the caller (`SenderIdentities::choose()`);
     * `$userId` is stored as «Ausgelöst von».
     *
     * @param list<Invoice> $documents
     * @return array{batch: string, queued: list<Invoice>, skipped: list<Invoice>}
     * @throws \Z77\Module\Mailing\Outbox\InvalidOutboxMailException a draft refused by the outbox — nothing written
     * @throws \RuntimeException the outbox is not installed
     */
    public function queue(array $documents, string $actor, SenderIdentity $sender, ?int $userId = null): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('The mail outbox (module-mailing) is not installed — documents cannot be sent by e-mail.');
        }
        $queued  = [];
        $skipped = [];
        $drafts  = [];
        foreach ($documents as $document) {
            if (self::skipReason($document) !== null) {
                $skipped[] = $document;
                continue;
            }
            $mail  = $this->compose($document, $sender);
            $draft = OutboxDraft::template($mail['to'], $mail['subject'], 'emails/documentMail', 'Z77\\Shared', ['body' => $mail['body']], self::customerOf($document))
                ->withLanguage($mail['language'])
                ->withAttachments([self::attachmentOf($document)])
                ->withSource(self::source($document))
                ->withSender($sender, $userId);
            $drafts[] = $draft;
            $queued[] = $document;
        }
        if ($drafts === []) {
            return ['batch' => '', 'queued' => [], 'skipped' => $skipped];
        }
        $rows = (new OutboxService($this->em))->queueBatch($drafts, $actor);

        return ['batch' => $rows[0]->getBatchId(), 'queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * What went out per document id — the outbox rows of the source `invoice:<id>`, oldest
     * first, as plain arrays (`to`, `state`, `created_at`, `sent_at`, `batch_id`,
     * `created_by`). Empty without the outbox.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    public function deliveries(array $ids): array
    {
        if ($ids === [] || !$this->available()) {
            return [];
        }
        /** @var OutboxMailRepository $mails */
        $mails  = $this->em->getRepository(OutboxMail::class);
        $result = [];
        foreach ($mails->deliveriesOf(array_map(static fn(int $id) => self::SOURCE_PREFIX . $id, $ids)) as $source => $rows) {
            $result[(int) substr($source, strlen(self::SOURCE_PREFIX))] = $rows;
        }

        return $result;
    }

    /**
     * Whether a document with these deliveries ({@see deliveries()}) is covered — a mail waits or went
     * out. With $document: per VERSION (ADR-054 decision 21) — only a mail that carries the current
     * revision's attachment covers it (a mail of revision 1 queued as `invoice-pdf:<id>` before the
     * revision-bound reference counts as revision 1); a new revision or a successor counts as unsent.
     */
    public static function isCovered(array $deliveries, ?Invoice $document = null): bool
    {
        foreach ($deliveries as $delivery) {
            if (!in_array($delivery['state'] ?? '', self::COVERING_STATES, true)) {
                continue;
            }
            if ($document === null || self::carriesVersion($delivery, $document)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the outbox row $delivery attached $document's CURRENT version. */
    private static function carriesVersion(array $delivery, Invoice $document): bool
    {
        $refs = $delivery['attachments'] ?? null;
        if (!is_array($refs)) {
            return true;   // an outbox without the column's answer — judged per document as before
        }
        $plain = InvoicePdfAttachment::TYPE . ':' . $document->getId();

        return in_array(self::attachmentOf($document), $refs, true) || ($document->getRevision() === 1 && in_array($plain, $refs, true));
    }

    /**
     * The documents «Alle per E-Mail senden …» still mails — mailable ({@see skipReason()} null)
     * and not covered (owner 2026-10-23). The ONE rule the result list, the confirmation and the
     * POST apply (Rule 7: the POST re-reads the deliveries, it trusts no page).
     *
     * @param list<Invoice> $documents
     * @param array<int, list<array<string, mixed>>> $deliveries {@see deliveries()}
     * @return list<Invoice>
     */
    public static function unsent(array $documents, array $deliveries): array
    {
        return array_values(array_filter($documents, static fn(Invoice $d) => self::skipReason($d) === null && !self::isCovered($deliveries[(int) $d->getId()] ?? [], $d)));
    }

    /**
     * What the result list says about the run's mails (owner 2026-10-23): how many documents can
     * be mailed, how many are still unsent, how many of the covered wait (a mail of theirs waits)
     * or went out, the newest batch and its outbox link, the pace.
     *
     * @param list<Invoice> $documents
     * @param array<int, list<array<string, mixed>>> $deliveries {@see deliveries()}
     * @return array{mailable: int, unsent: int, waiting: int, sent: int, batch: string, outboxUrl: string, rate: string}
     */
    public function runStatus(array $documents, array $deliveries): array
    {
        $mailable = 0;
        $waiting  = 0;
        $sent     = 0;
        $latest   = null;
        foreach ($documents as $document) {
            $rows = $deliveries[(int) $document->getId()] ?? [];
            if (self::skipReason($document) === null) {
                $mailable++;
            }
            if (!self::isCovered($rows, $document)) {
                continue;
            }
            // Covered: a mail waits (then it counts as waiting) or one went out.
            array_filter($rows, static fn(array $r) => in_array($r['state'], self::WAITING_STATES, true)) !== [] ? $waiting++ : $sent++;
        }
        foreach ($deliveries as $rows) {
            foreach ($rows as $row) {
                $latest = $latest === null || $row['id'] > $latest['id'] ? $row : $latest;
            }
        }
        $batch = (string) ($latest['batch_id'] ?? '');

        return [
            'mailable'  => $mailable,
            'unsent'    => count(self::unsent($documents, $deliveries)),
            'waiting'   => $waiting,
            'sent'      => $sent,
            'batch'     => $batch,
            'outboxUrl' => $batch !== '' && $this->available() ? OutboxListing::batchUrl($batch) : '',
            'rate'      => $this->rate(),
        ];
    }

    /** The outbox's pace in German («10 pro Minute») from its ONE setting; '' without the outbox. */
    public function rate(): string
    {
        return $this->available() ? OutboxSettings::fromModules(DI::getModuleManager())->rateText() : '';
    }

    /** German, one line per delivery: «gesendet an a@b.ch am 22.10.2026 18:04» — the mask's history and the result list. */
    public static function deliveryLine(array $delivery): string
    {
        $when = static fn(?\DateTimeImmutable $at): string => $at !== null ? $at->format('d.m.Y H:i') : '';

        $as = trim((string) ($delivery['sender'] ?? ''));

        return (match ($delivery['state']) {
            'sent'      => 'per E-Mail an ' . $delivery['to'] . ' gesendet am ' . $when($delivery['sent_at']),
            'failed'    => 'E-Mail an ' . $delivery['to'] . ' fehlgeschlagen (eingereiht ' . $when($delivery['created_at']) . ')',
            'cancelled' => 'E-Mail an ' . $delivery['to'] . ' abgebrochen (eingereiht ' . $when($delivery['created_at']) . ')',
            default     => 'E-Mail an ' . $delivery['to'] . ' im Postausgang seit ' . $when($delivery['created_at']),
        }) . ($as !== '' ? ' · als ' . $as : '');
    }

    private static function customerOf(Invoice $document): string
    {
        $address = $document->getAddress();

        return trim($address->getFirstName() . ' ' . $address->getName());
    }

    private function emailService(): EmailService
    {
        return $this->emailService ??= DI::getEmailService();
    }

    private function kinds(): DocumentMailKinds
    {
        return $this->kinds ??= DocumentMailKinds::fromModules(DI::getModuleManager());
    }
}
