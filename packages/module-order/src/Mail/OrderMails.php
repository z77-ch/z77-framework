<?php

namespace Z77\Module\Order\Mail;

use Z77\Core\DI;
use Z77\Module\Debtor\Mail\DocumentMail;
use Z77\Module\Debtor\Mail\DocumentMailer;
use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Services\OrderService;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\DocumentMailKinds;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\SenderIdentity;
use Z77\Shared\Money\AmountFormat;

/**
 * The order's documents by e-mail (owner 2026-11-13, «the way invoices are sent») — quote, order
 * confirmation and delivery note, one mail per click, through debtor's shared
 * {@see DocumentMailer} (Rule 9 — the recipient rule, the composition, the queue and the history
 * are the invoice's):
 *
 *   - WHICH documents: the ones the order HAS today — a print of them would not move it
 *     ({@see documentsOf()}): a quote (phase `new`) its «Offerte»; from `confirmed` on the
 *     «Auftragsbestätigung»; from `delivered` on also the «Lieferschein». The order confirmation of
 *     a quote and the delivery note of a confirmed order are not mailed — their print MOVES the
 *     order (`printConfirmation()` / `printDeliveryNote()`, D6), and a mail does not. A cancelled
 *     or deleted order mails nothing;
 *   - the RECIPIENT is the e-mail of the order's BILLING address — the snapshot on the order, else
 *     the customer's invoice address (`OrderService::billingAddressOf()`, the address the quote /
 *     confirmation prints and the invoice will carry); the delivery note goes there too. None →
 *     «keine E-Mail-Adresse», refused, never a silent failure;
 *   - the KIND is the document's (`orderConfig` `documentMails`: `quote`, `order-confirmation`,
 *     `delivery-note`), in the CUSTOMER's language (the contact's, else the installation's default —
 *     the language debtor gives the order's invoice); placeholders `{nummer}`, `{kunde}`, `{betrag}`
 *     (the stored total «CHF 1'234.50»), `{datum}` (the order date; the delivery note's «Lieferdatum»
 *     = «Leistung von», else today — as the PDF prints it), `{absender}`, `{anzeigename}`;
 *   - the ATTACHMENT is `order-pdf:<order id>@<kind>` ({@see OrderPdfAttachment}) — the PDF rendered
 *     at SEND time from the order as it then stands, never a stored file; the SOURCE is
 *     `<kind>:<order id>` (`quote:12`), how the outbox tells what went out for that document.
 *
 * Sending again is allowed — the confirmation says what went out before.
 */
final class OrderMails
{
    public const QUOTE         = 'quote';
    public const CONFIRMATION  = 'order-confirmation';
    public const DELIVERY_NOTE = 'delivery-note';

    /** Mail kind → the document's German name (the PDF's title). */
    public const NAMES = [
        self::QUOTE         => 'Offerte',
        self::CONFIRMATION  => 'Auftragsbestätigung',
        self::DELIVERY_NOTE => 'Lieferschein',
    ];

    private DocumentMailer $mailer;

    public function __construct(
        UnifiedEntityManager $em,
        private OrderService $service,
        ?EmailService $emailService = null,
        ?DocumentMailKinds $kinds = null,
    ) {
        $this->mailer = new DocumentMailer($em, $emailService, $kinds);
    }

    public function mailer(): DocumentMailer
    {
        return $this->mailer;
    }

    /**
     * The documents of $order that can be mailed today, by its $phase — the ones whose print would
     * not move the order (see the class doc); [] for a deleted order, a cancelled one, an unknown phase.
     *
     * @return list<string> mail kinds
     */
    public static function documentsOf(SalesOrder $order, ?OrderPhase $phase): array
    {
        if ($order->isDeleted()) {
            return [];
        }

        return match ($phase) {
            OrderPhase::New                         => [self::QUOTE],
            OrderPhase::Confirmed                   => [self::CONFIRMATION],
            OrderPhase::Delivered, OrderPhase::Closed => [self::CONFIRMATION, self::DELIVERY_NOTE],
            default                                 => [],
        };
    }

    /** «Offerte 12», «Lieferschein 12». */
    public static function documentName(string $kind, SalesOrder $order): string
    {
        return (self::NAMES[$kind] ?? $kind) . ' ' . $order->documentNumber();
    }

    public static function sourcePrefix(string $kind): string
    {
        return $kind . ':';
    }

    /** The billing e-mail of $order as it stands ('' without one). */
    public function recipientOf(SalesOrder $order): string
    {
        return trim($this->service->billingAddressOf($order)?->getEmail() ?? '');
    }

    /** Why $order's documents get no mail — «keine E-Mail-Adresse» / «E-Mail-Adresse ungültig» — null when they can. */
    public function skipReason(SalesOrder $order): ?string
    {
        return DocumentMailer::addressProblem($this->recipientOf($order));
    }

    /** $order's document $kind as the shared core's {@see DocumentMail}. */
    public function documentMail(SalesOrder $order, string $kind): DocumentMail
    {
        if (!isset(self::NAMES[$kind])) {
            throw new \InvalidArgumentException("'{$kind}' is no order document mail kind.");
        }
        $address  = $this->service->billingAddressOf($order);
        $customer = $address !== null ? trim($address->getFirstName() . ' ' . $address->getName()) : $order->getContact()->displayName();
        $total    = $order->getTotalGross();
        $date     = $kind === self::DELIVERY_NOTE ? ($order->getServiceFrom() ?? new \DateTimeImmutable('today')) : $order->getOrderDate();
        $language = $order->getContact()->getLanguage();

        return new DocumentMail(
            $kind,
            $this->recipientOf($order),
            $customer,
            $language !== '' ? $language : DI::getI18n()->getDefaultLanguage(),
            [
                'nummer'   => $order->documentNumber(),
                'kunde'    => $customer,
                'betrag'   => $total !== null ? $order->getCurrency() . ' ' . AmountFormat::of($total) : '',
                'datum'    => $date->format('d.m.Y'),
                // The order's date of service — the order confirmation's fact line «Termin …»
                // (board «E-Mail-Standard»); '' without one: that segment is left out.
                'termin'   => $order->getServiceFrom()?->format('d.m.Y') ?? '',
                'absender' => $this->mailer->senderCompany(),
            ],
            OrderPdfAttachment::reference($order, $kind),
            self::sourcePrefix($kind) . $order->getId(),
            $address,
        );
    }

    /**
     * The mail the confirmation shows (to, subject, language).
     *
     * @return array{to: string, subject: string, body: string, language: string, kind: string}
     */
    public function compose(SalesOrder $order, string $kind, ?SenderIdentity $sender = null): array
    {
        return $this->mailer->compose($this->documentMail($order, $kind), $sender);
    }

    /**
     * Queues the mail of $order's document $kind (one outbox mail, its own batch). The caller has
     * checked the document is one of {@see documentsOf()} and the address ({@see skipReason()}).
     *
     * @throws \LogicException no deliverable address
     * @throws \Z77\Module\Mailing\Outbox\InvalidOutboxMailException
     * @throws \RuntimeException the outbox is not installed
     */
    public function queue(SalesOrder $order, string $kind, string $actor, SenderIdentity $sender, ?int $userId = null): string
    {
        return $this->mailer->queue([$this->documentMail($order, $kind)], $actor, $sender, $userId);
    }

    /**
     * What went out for the document $kind per order id.
     *
     * @param list<int> $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function deliveries(string $kind, array $orderIds): array
    {
        return $this->mailer->deliveries(self::sourcePrefix($kind), $orderIds);
    }
}
