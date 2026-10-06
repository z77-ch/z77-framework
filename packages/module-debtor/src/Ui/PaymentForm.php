<?php

namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Payments\PaymentDraft;
use Z77\Shared\Money\Money;

/**
 * The «Zahlung erfassen» form of a final invoice (P4 part 1) — the state
 * between the posted body and the {@see PaymentDraft}: the value date, the
 * three amounts (Zahlung / Skonto / Verlust), the payment target the money
 * arrived on, a note. Reads what the browser sent, keeps it for
 * re-rendering, turns it into a draft when every field parses; the RULES
 * (open amount, final state, the target) are the service's — a refusal
 * comes back as the general error.
 */
final class PaymentForm
{
    private const AMOUNT = '/^\d{1,11}(\.\d{1,2})?$/';

    /** @var array<string, string> field → value as posted */
    private array $values = ['date' => '', 'payment' => '', 'discount' => '', 'loss' => '', 'target' => '', 'note' => ''];

    /** @var array<string, string> field → message */
    private array $errors = [];

    /** @var list<string> */
    private array $general = [];

    public function __construct(private readonly string $currency) {}

    public function startBlank(\DateTimeImmutable $today, string $defaultTarget): self
    {
        $this->values['date']   = $today->format('Y-m-d');
        $this->values['target'] = $defaultTarget;

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

    /** The draft, or null with the field errors set. */
    public function toDraft(int $invoiceId): ?PaymentDraft
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
        if ($this->errors === [] && $amounts['payment']->add($amounts['discount'])->add($amounts['loss'])->isZero()) {
            $this->errors['payment'] = 'Zahlung, Skonto oder Verlust muss grösser als 0.00 sein.';
        }
        if ($this->errors === [] && $amounts['payment']->isPositive() && $this->values['target'] === '') {
            $this->errors['target'] = 'Eine Zahlung braucht ein Zahlungsziel.';
        }
        if ($this->errors !== []) {
            return null;
        }

        return new PaymentDraft($invoiceId, $date, $amounts['payment'], $amounts['discount'], $amounts['loss'], $this->values['target'], $this->values['note'] === '' ? null : $this->values['note']);
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
