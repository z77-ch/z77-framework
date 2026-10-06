<?php

namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Entities\Payment;
use Z77\Module\Debtor\Payments\PaymentDraft;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The «Zahlung erfassen» / «Zahlung ändern» form of a final invoice (P4
 * part 1) — the state between the posted body and the {@see PaymentDraft}:
 * the value date, the money that arrived and the LEDGER ACCOUNT it went to
 * (bank, cash register, a clearing account — the office chooses; the
 * document's payment target is the proposal, owner 2026-10-06), the Skonto
 * granted, the amount written off, a note. «Rest als Verlust ausbuchen»
 * (`rest_loss`) fills the loss with what is left of the open amount after
 * payment and discount — the owner's «Betrag eingeben, Differenz auf das
 * Verlustkonto». Reads what the browser sent, keeps it for re-rendering,
 * turns it into a draft when every field parses; the RULES (open amount,
 * final state, the account) are the service's — a refusal comes back as the
 * general error.
 */
final class PaymentForm
{
    private const AMOUNT = '/^\d{1,11}(\.\d{1,2})?$/';

    /** @var array<string, string> field → value as posted */
    private array $values = ['date' => '', 'payment' => '', 'account' => '', 'discount' => '', 'loss' => '', 'rest_loss' => '', 'note' => ''];

    /** @var array<string, string> field → message */
    private array $errors = [];

    /** @var list<string> */
    private array $general = [];

    public function __construct(private readonly string $currency) {}

    public function startBlank(\DateTimeImmutable $today, string $defaultAccount): self
    {
        $this->values['date']    = $today->format('Y-m-d');
        $this->values['account'] = $defaultAccount;

        return $this;
    }

    /** The form prefilled from an existing settlement (an edit). */
    public function startFrom(Payment $payment): self
    {
        $byKind = [];
        foreach ($payment->getAllocations() as $allocation) {
            $byKind[$allocation->kind()->value] = $allocation->getAmount();
        }
        $this->values['date']     = $payment->getDate()->format('Y-m-d');
        $this->values['payment']  = isset($byKind['payment']) ? AmountFormat::field($byKind['payment']) : '';
        $this->values['account']  = $payment->getAccountNumber();
        $this->values['discount'] = isset($byKind['discount']) ? AmountFormat::field($byKind['discount']) : '';
        $this->values['loss']     = isset($byKind['loss']) ? AmountFormat::field($byKind['loss']) : '';
        $this->values['note']     = (string) $payment->getNote();

        return $this;
    }

    /** @param array<string, mixed> $post */
    public function fromPost(array $post): self
    {
        foreach (array_keys($this->values) as $key) {
            $this->values[$key] = trim((string) ($post[$key] ?? ''));
        }

        return $this;
    }

    /**
     * The draft, or null with the field errors set. $open is the amount
     * «Rest als Verlust» completes to (the open amount of the invoice, plus
     * this payment's own allocations on an edit).
     */
    public function toDraft(int $invoiceId, Money $open, string $targetCode = ''): ?PaymentDraft
    {
        $this->errors = [];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->values['date']);
        if ($date === false || $date->format('Y-m-d') !== $this->values['date']) {
            $this->errors['date'] = 'Datum als JJJJ-MM-TT.';
        }
        $amounts = [];
        foreach (['payment' => 'Zahlung', 'discount' => 'Skonto', 'loss' => 'Verlust'] as $key => $label) {
            $raw = $this->values[$key] === '' ? '0' : $this->values[$key];
            if (!preg_match(self::AMOUNT, $raw)) {
                $this->errors[$key] = $label . ': Betrag mit höchstens zwei Dezimalen, nicht negativ.';
                continue;
            }
            $amounts[$key] = Money::fromDecimal($raw, $this->currency);
        }
        if ($this->errors === [] && $this->values['rest_loss'] !== '') {
            $rest = $open->subtract($amounts['payment'])->subtract($amounts['discount']);
            if ($rest->isNegative()) {
                $this->errors['loss'] = 'Zahlung und Skonto übersteigen den offenen Betrag — kein Rest für den Verlust.';
            } else {
                $amounts['loss']      = $rest;
                $this->values['loss'] = AmountFormat::field($rest);
            }
        }
        if ($this->errors === [] && $amounts['payment']->add($amounts['discount'])->add($amounts['loss'])->isZero()) {
            $this->errors['payment'] = 'Zahlung, Skonto oder Verlust muss grösser als 0.00 sein.';
        }
        if ($this->errors === [] && $amounts['payment']->isPositive()) {
            if ($this->values['account'] === '') {
                $this->errors['account'] = 'Eine Zahlung braucht ein Konto (Bank, Kasse …).';
            } elseif (!preg_match('/^\d{1,10}$/', $this->values['account'])) {
                $this->errors['account'] = 'Konto: nur Ziffern (z.B. 1020).';
            }
        }
        if ($this->errors !== []) {
            return null;
        }

        return new PaymentDraft(
            $invoiceId,
            $date,
            $amounts['payment'],
            $amounts['discount'],
            $amounts['loss'],
            $targetCode,
            $this->values['note'] === '' ? null : $this->values['note'],
            $amounts['payment']->isPositive() ? $this->values['account'] : '',
        );
    }

    public function value(string $key): string { return $this->values[$key] ?? ''; }
    public function error(string $key): string { return $this->errors[$key] ?? ''; }

    /** @return list<string> */
    public function generalErrors(): array { return $this->general; }
    public function addGeneralError(string $message): void { $this->general[] = $message; }

    public function hasErrors(): bool
    {
        return $this->errors !== [] || $this->general !== [];
    }

    /** The ids of the invalid fields, for the action bar's «N Fehler» link (ADR-049). */
    public function invalidIds(): array
    {
        return array_map(static fn(string $key) => 'payment-' . $key, array_keys($this->errors));
    }
}
