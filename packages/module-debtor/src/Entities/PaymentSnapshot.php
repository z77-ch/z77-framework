<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Debtor\Invoicing\QrReference;
use Z77\Module\Debtor\Services\Creditor;

/**
 * The PAYMENT PART a document prints, frozen at issue (P3 part 3; the
 * pending of `debtor.md` «the payment target and the reference become
 * columns of `invoice`»): which payment target, the account printed, the
 * reference type and the reference, the unstructured message, and the
 * creditor block as it resolved (the target's holder fields, the mandator's
 * address as field-by-field fallback — {@see Creditor}). An issued document
 * keeps what it printed: a later change of the payment target, its holder,
 * the mandator's address or the reference rule never alters it — the QR-bill
 * is rendered from THIS snapshot only (`Invoicing\QrBill::of()`).
 *
 * The reference rule (owner 2026-09-23, wdv-630 `PrepaymentQrCode`,
 * confirmed): the USE CASE decides. An invoice has a reference (its number):
 * with a QR-IBAN on the target → that QR-IBAN + QRR ({@see QrReference});
 * a target with only a plain IBAN → that IBAN + NON, the document named in
 * the message so the payment can still be assigned. A QR-IBAN is NEVER
 * printed with NON (banks reject it). No SCOR.
 *
 * A credit note has NO payment part, and neither has a document issued
 * without a payment target: every column empty ({@see none()}).
 *
 * An embeddable (`pay_` on `invoice`), flat columns like the address
 * snapshot — readable with a query tool, one shape the dunning notice (P4)
 * reuses. Account 21, reference 27 and message 140 are the Swiss QR
 * specification's lengths; the creditor columns are as long as the fields
 * they may come from (the mandator's letterhead: name and street 120, city
 * 70), so the snapshot holds exactly what resolved — `QrBill` compares it
 * with the specification (70 / 70 / 16 / 16 / 35 / 2) and reports a value
 * that does not fit instead of printing it cut.
 */
#[ORM\Embeddable]
final class PaymentSnapshot
{
    public const REFERENCE_QRR = 'QRR';
    public const REFERENCE_NON = 'NON';

    public const MESSAGE_LENGTH = 140;

    /** {@see PaymentTarget::$code} — by code, no foreign key (ADR-043 decision 19); '' = no payment part. */
    #[ORM\Column(name: 'target_code', length: 16)]
    private string $targetCode = '';

    /** The IBAN or QR-IBAN printed, normalized. */
    #[ORM\Column(length: 21)]
    private string $account = '';

    /** QRR | NON | '' (no payment part). */
    #[ORM\Column(name: 'reference_type', length: 3)]
    private string $referenceType = '';

    #[ORM\Column(length: 27)]
    private string $reference = '';

    /** The unstructured message («Rechnung 12»). */
    #[ORM\Column(length: 140)]
    private string $message = '';

    #[ORM\Column(name: 'creditor_name', length: 120)]
    private string $creditorName = '';

    #[ORM\Column(name: 'creditor_street', length: 120)]
    private string $creditorStreet = '';

    #[ORM\Column(name: 'creditor_house_no', length: 16)]
    private string $creditorHouseNo = '';

    #[ORM\Column(name: 'creditor_zip', length: 16)]
    private string $creditorZip = '';

    #[ORM\Column(name: 'creditor_city', length: 70)]
    private string $creditorCity = '';

    #[ORM\Column(name: 'creditor_country', length: 2)]
    private string $creditorCountry = '';

    private function __construct() {}

    /** No payment part — a credit note, or a document issued without a payment target. */
    public static function none(): self
    {
        return new self();
    }

    /**
     * The payment part of document $number, from the target and the creditor
     * block as they read NOW. A credit note gets {@see none()}. The creditor
     * fields are copied as they resolved, never cut; `QrBill` compares them
     * with the specification and reports a value that does not fit.
     */
    public static function forDocument(InvoiceKind $kind, int $number, string $message, ?PaymentTarget $target, ?Creditor $creditor): self
    {
        $snapshot = new self();
        if ($kind === InvoiceKind::CreditNote || $target === null) {
            return $snapshot;
        }
        $snapshot->targetCode = $target->getCode();
        if ($target->hasQrIban()) {
            $snapshot->account       = $target->getQrIban();
            $snapshot->referenceType = self::REFERENCE_QRR;
            $snapshot->reference     = QrReference::forNumber($number);
        } else {
            $snapshot->account       = $target->getIban();
            $snapshot->referenceType = self::REFERENCE_NON;
        }
        $snapshot->message = mb_substr(trim($message), 0, self::MESSAGE_LENGTH);
        if ($creditor !== null) {
            $snapshot->creditorName    = $creditor->name;
            $snapshot->creditorStreet  = $creditor->street;
            $snapshot->creditorHouseNo = $creditor->houseNo;
            $snapshot->creditorZip     = $creditor->zip;
            $snapshot->creditorCity    = $creditor->city;
            $snapshot->creditorCountry = $creditor->country;
        }

        return $snapshot;
    }

    public function hasPaymentPart(): bool { return $this->referenceType !== ''; }
    public function getTargetCode(): string { return $this->targetCode; }
    public function getAccount(): string { return $this->account; }
    public function getReferenceType(): string { return $this->referenceType; }
    public function getReference(): string { return $this->reference; }
    public function getMessage(): string { return $this->message; }
    public function getCreditorName(): string { return $this->creditorName; }
    public function getCreditorStreet(): string { return $this->creditorStreet; }
    public function getCreditorHouseNo(): string { return $this->creditorHouseNo; }
    public function getCreditorZip(): string { return $this->creditorZip; }
    public function getCreditorCity(): string { return $this->creditorCity; }
    public function getCreditorCountry(): string { return $this->creditorCountry; }
}
