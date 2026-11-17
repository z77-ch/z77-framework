<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The result of the CHECK of one bank-driven bank account over one period (ADR-061 decisions 23,
 * 25, stage C4) — pure data, computed by {@see BankReconciliation::check()}, never stored.
 *
 * The check compares CHANGES: the change of the bank's ledger account in the period (the year
 * opening left out) must equal the sum of the «geschäftlich» movements booked by the bank in the
 * period. What it names:
 *
 *   - {@see $difference} = ledger change − business movements;
 *   - {@see $entries} — «Buchung ohne Bewegung»: postings on the ledger account whose record is
 *     linked to no movement of this bank account booked in the period (a posting whose movement
 *     lies in ANOTHER period carries that day as its note);
 *   - {@see $unbooked} — business movements of the period whose record has no posting in it;
 *   - {@see $review} — movements still «zu prüfen»;
 *   - whether the statement is read from the period's start to its end.
 *
 * {@see problems()} are the German sentences of what fails — the close check yields them as
 * BLOCKING findings, the window «Abgleich» shows the figures.
 */
final class BankCheck
{
    /** How many postings a sentence names before it says «…». */
    public const NAMED = 8;

    /**
     * @param \DateTimeImmutable      $from       the first day checked: the period's start, or «Abgleich ab» when that is later
     * @param \DateTimeImmutable|null $chainFrom  the first day the stored statements cover; null = nothing read
     * @param \DateTimeImmutable|null $coveredTo  «eingelesen bis»; null = nothing read
     * @param int                     $review     movements of the period «zu prüfen»
     * @param Money                   $business   Σ of the «geschäftlich» movements (credits +, debits −)
     * @param Money                   $private    Σ of the «privat» movements
     * @param Money                   $ledger     the change of the ledger account (debit − credit), the year opening left out
     * @param list<array{ref: string, date: string, text: string, amount: string, note: string}> $entries the first postings without a movement
     * @param int                     $entryCount how many postings are without a movement
     * @param array{count: int, sum: Money} $unbooked business movements of the period without a posting in it
     */
    public function __construct(
        public readonly BankAccount $account,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly ?\DateTimeImmutable $chainFrom,
        public readonly ?\DateTimeImmutable $coveredTo,
        public readonly int $review,
        public readonly Money $business,
        public readonly Money $private,
        public readonly Money $ledger,
        public readonly array $entries,
        public readonly int $entryCount,
        public readonly array $unbooked,
    ) {}

    /** Ledger change − business movements: 0.00 when the books agree with the bank. */
    public function difference(): Money
    {
        return $this->ledger->subtract($this->business);
    }

    /** The statement covers the period's first day. */
    public function isReadFrom(): bool
    {
        return $this->chainFrom !== null && $this->chainFrom->format('Y-m-d') <= $this->from->format('Y-m-d');
    }

    /** The statement is read up to the period's end. */
    public function isReadTo(): bool
    {
        return $this->coveredTo !== null && $this->coveredTo->format('Y-m-d') >= $this->to->format('Y-m-d');
    }

    /** The sums agree and every posting has its movement. */
    public function agrees(): bool
    {
        return $this->difference()->isZero() && $this->entryCount === 0;
    }

    public function passes(): bool
    {
        return $this->problems() === [];
    }

    /**
     * What fails, each a German sentence naming the bank account — empty when the check passes.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $name     = 'Bankkonto «' . $this->account->getLabel() . '»';
        $problems = [];
        if ($this->coveredTo === null) {
            $problems[] = $name . ': noch kein Kontoauszug eingelesen — bis ' . $this->to->format('d.m.Y') . ' einlesen (Finanzen › Bankkonto).';
        } else {
            if (!$this->isReadFrom()) {
                $problems[] = $name . ': der Kontoauszug beginnt erst am ' . $this->chainFrom->format('d.m.Y') . ', «Abgleich ab» ist der ' . $this->from->format('d.m.Y')
                    . ' — «Abgleich ab» auf den Beginn des Kontoauszugs setzen (Stammdaten › Finanzen › Bankkonten).';
            }
            if (!$this->isReadTo()) {
                $problems[] = $name . ': der Kontoauszug ist nur bis ' . $this->coveredTo->format('d.m.Y') . ' eingelesen — bis ' . $this->to->format('d.m.Y') . ' einlesen (Finanzen › Bankkonto).';
            }
        }
        if ($this->review > 0) {
            $problems[] = $name . ': ' . ($this->review === 1 ? '1 Bewegung ist' : $this->review . ' Bewegungen sind') . ' noch «zu prüfen» — zuordnen, buchen oder als privat bestätigen (Finanzen › Bankkonto, Ansicht «Zu prüfen»).';
        }
        if (!$this->agrees()) {
            $ledger   = $this->account->getLedgerAccount();
            $sentence = $name . ': Konto ' . $ledger . ' hat sich um ' . AmountFormat::of($this->ledger) . ' verändert, die geschäftlichen Bankbewegungen ergeben '
                . AmountFormat::of($this->business) . ' — Differenz ' . AmountFormat::of($this->difference()) . '.';
            if ($this->entryCount > 0) {
                $named    = array_map(static fn(array $e): string => $e['ref'], array_slice($this->entries, 0, self::NAMED));
                $sentence .= ' ' . ($this->entryCount === 1 ? '1 Buchung' : $this->entryCount . ' Buchungen') . ' ohne Bewegung: ' . implode(', ', $named) . ($this->entryCount > count($named) ? ', …' : '') . '.';
            }
            if ($this->unbooked['count'] > 0) {
                $sentence .= ' ' . ($this->unbooked['count'] === 1 ? '1 geschäftliche Bewegung' : $this->unbooked['count'] . ' geschäftliche Bewegungen')
                    . ' ohne Buchung im Zeitraum (' . AmountFormat::of($this->unbooked['sum']) . ').';
            }
            $problems[] = $sentence . ' Einzeln unter Finanzen › Bankkonto, «Abgleich …».';
        }

        return $problems;
    }
}
