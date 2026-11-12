<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Ui\OneLineEntryForm;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Services\VatRates;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The posting of ONE receipt — what «Journal verbuchen» books and what its preview shows. A
 * receipt IS a one-line entry (Soll | Datum | Text | Haben | Betrag, optional VAT code): the lines
 * are built by financial's {@see OneLineEntryForm} — its VAT split (gross mode at the rate of the
 * receipt date, net on the P&L side, the tax line on the mandator's VAT account, zero-rated and
 * exempt codes without a tax line) exists ONCE and is not repeated here. The form answers a
 * MANUAL request; this class re-wraps its date, text and lines as the GENERATED request of the
 * receipt (source `receipt`, ref = the id, key `receipt:{id}`).
 *
 * The form is a UI class used as a builder: financial has no public line builder for a one-line
 * entry (pending in `receipt.md`). Nothing here posts.
 */
final class ReceiptPostingBuilder
{
    /** The form's fields in display order with their German name — for one refusal message. */
    private const FIELDS = ['date' => 'Datum', 'text' => 'Text', 'debit' => 'Soll', 'credit' => 'Haben', 'amount' => 'Betrag', 'tax_code' => 'MWST', 'tax_amount' => 'Steuerbetrag'];

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * The generated posting of $receipt.
     *
     * @throws ReceiptRefusedException INCOMPLETE (text, an account or the VAT decision missing) | NOT_POSTABLE (the lines cannot be built)
     */
    public function build(Receipt $receipt): PostingRequest
    {
        $missing = $receipt->missing();
        if ($missing !== []) {
            throw new ReceiptRefusedException(ReceiptRefusedException::INCOMPLETE, self::name($receipt) . ' ist unvollständig — es fehlt: ' . implode(', ', $missing) . '.');
        }
        try {
            $manual = $this->lines($receipt->getReceiptDate(), $receipt->getText(), $receipt->getAmount(), (string) $receipt->getDebitAccount(), (string) $receipt->getCreditAccount(), $receipt->getTaxCode());
        } catch (ReceiptRefusedException $e) {
            throw new ReceiptRefusedException($e->reason, self::name($receipt) . ': ' . $e->getMessage(), $e);
        }

        return PostingRequest::generated(
            $manual->date,
            $manual->text,
            Receipt::SOURCE_TYPE,
            (string) $receipt->getId(),
            $receipt->idempotencyKey(),
            $manual->lines,
        );
    }

    /**
     * The one-line entry's request for these values — what {@see build()} wraps.
     *
     * @throws ReceiptRefusedException NOT_POSTABLE with the form's German messages
     */
    public function lines(\DateTimeImmutable $date, string $text, Money $gross, string $debit, string $credit, ?string $taxCode): PostingRequest
    {
        $form = new OneLineEntryForm(
            ReceiptFields::currency(),
            $this->em->getRepository(Account::class),
            $this->em->getRepository(TaxCode::class),
            VatRates::from($this->em),
            new LedgerService($this->em),
        );
        $request = $form->fromPost([
            'date'       => $date->format('Y-m-d'),
            'text'       => $text,
            'debit'      => $debit,
            'credit'     => $credit,
            'amount'     => $gross->toDecimal(),
            'vat'        => $taxCode !== null ? '1' : '',
            'tax_code'   => $taxCode ?? '',
            'tax_amount' => '',
        ])->toRequest();
        if ($request !== null) {
            return $request;
        }

        $messages = [];
        foreach (self::FIELDS as $field => $name) {
            if ($form->error($field) !== '') {
                $messages[] = $name . ': ' . $form->error($field);
            }
        }
        $messages = array_merge($messages, $form->generalErrors());

        throw new ReceiptRefusedException(ReceiptRefusedException::NOT_POSTABLE, implode(' ', $messages));
    }

    /** «Beleg 17 vom 03.11.2026» — how every message names a receipt. */
    public static function name(Receipt $receipt): string
    {
        return 'Beleg ' . $receipt->getId() . ' vom ' . $receipt->getReceiptDate()->format('d.m.Y');
    }
}
