<?php

namespace Z77\Module\Debtor\Mail;

use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\DocumentMailKinds;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\SenderIdentity;
use Z77\Shared\Money\AmountFormat;

/**
 * The dunning notice by e-mail (owner 2026-11-13, «the way invoices are sent») — one notice, one
 * mail, through the shared {@see DocumentMailer}:
 *
 *   - the RECIPIENT is the billing e-mail ON THE INVOICE (`addr_email`, the snapshot rule — the
 *     notice PDF is addressed to the same snapshot); none → «keine E-Mail-Adresse», skipped;
 *   - the KIND is `dunning` «Mahnung» (`debtorConfig` `documentMails`), in the invoice's language;
 *     placeholders `{nummer}` the invoice number, `{kunde}`, `{betrag}` the open amount of the notice
 *     («CHF 1'234.50»), `{datum}` the notice date, `{faellig}` the invoice's due date, `{absender}`,
 *     `{anzeigename}`;
 *   - the ATTACHMENT is `dunning-pdf:<notice id>` ({@see DunningPdfAttachment}) — the notice PDF
 *     rendered at SEND time, as «PDF» on the screen renders it (nothing of it is stored); the
 *     SOURCE is `dunning:<notice id>`.
 *
 * Sending again is allowed (a notice is sent by hand, one at a time — «Erneut senden …»); the
 * history is the outbox's ({@see deliveries()}).
 */
final class DunningMails
{
    public const KIND = 'dunning';

    public const SOURCE_PREFIX = 'dunning:';

    private DocumentMailer $mailer;

    public function __construct(UnifiedEntityManager $em, ?EmailService $emailService = null, ?DocumentMailKinds $kinds = null)
    {
        $this->mailer = new DocumentMailer($em, $emailService, $kinds);
    }

    public function mailer(): DocumentMailer
    {
        return $this->mailer;
    }

    public static function source(DunningNotice $notice): string
    {
        return self::SOURCE_PREFIX . $notice->getId();
    }

    /** Why $notice gets no mail — «keine E-Mail-Adresse» / «E-Mail-Adresse ungültig» — null when it can. */
    public static function skipReason(DunningNotice $notice): ?string
    {
        return DocumentMailer::addressProblem($notice->getInvoice()->getAddress()->getEmail());
    }

    /** $notice as the shared core's {@see DocumentMail}. */
    public function documentMail(DunningNotice $notice): DocumentMail
    {
        $invoice = $notice->getInvoice();
        $address = $invoice->getAddress();

        return new DocumentMail(
            self::KIND,
            $address->getEmail(),
            trim($address->getFirstName() . ' ' . $address->getName()),
            $invoice->getLanguage(),
            [
                'nummer'   => (string) $invoice->getNumber(),
                'kunde'    => trim($address->getFirstName() . ' ' . $address->getName()),
                'betrag'   => $invoice->getCurrency() . ' ' . AmountFormat::of($notice->getOpenAmount()),
                'datum'    => $notice->getRun()->getRunDate()->format('d.m.Y'),
                'faellig'  => $invoice->getDueDate()->format('d.m.Y'),
                'absender' => $this->mailer->senderCompany(),
            ],
            DunningPdfAttachment::TYPE . ':' . $notice->getId(),
            self::source($notice),
            $address,
        );
    }

    /**
     * The mail the confirmation shows (to, subject, language).
     *
     * @return array{to: string, subject: string, body: string, language: string, kind: string}
     */
    public function compose(DunningNotice $notice, ?SenderIdentity $sender = null): array
    {
        return $this->mailer->compose($this->documentMail($notice), $sender);
    }

    /**
     * Queues the notice's mail (one outbox mail, its own batch) — refused before when it has no
     * address ({@see skipReason()}).
     *
     * @throws \LogicException no deliverable address
     * @throws \Z77\Module\Mailing\Outbox\InvalidOutboxMailException
     * @throws \RuntimeException the outbox is not installed
     */
    public function queue(DunningNotice $notice, string $actor, SenderIdentity $sender, ?int $userId = null): string
    {
        if (($reason = self::skipReason($notice)) !== null) {
            throw new \LogicException('Mahnung ' . $notice->getId() . ': ' . $reason);
        }

        return $this->mailer->queue([$this->documentMail($notice)], $actor, $sender, $userId);
    }

    /**
     * @param list<int> $noticeIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function deliveries(array $noticeIds): array
    {
        return $this->mailer->deliveries(self::SOURCE_PREFIX, $noticeIds);
    }
}
