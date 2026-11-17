<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankStatement;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * What {@see BankStatementService::import()} did with ONE statement of a file: the stored
 * {@see BankStatement} — or null when the statement brought nothing new and nothing was stored —,
 * how many movements are new and how many the account already held, how many unbooked entries
 * were left out, and where the account stands now: «eingelesen bis» and the balance there.
 */
final class BankImportResult
{
    public function __construct(
        public readonly BankAccount $bankAccount,
        public readonly ?BankStatement $statement,
        public readonly int $newMovements,
        public readonly int $knownMovements,
        public readonly int $skipped,
        public readonly \DateTimeImmutable $coveredTo,
        public readonly Money $balance,
    ) {}

    /** The one German sentence the upload answers with. */
    public function message(): string
    {
        $until = 'eingelesen bis ' . $this->coveredTo->format('d.m.Y');
        if ($this->statement === null) {
            return 'Nichts Neues — das Bankkonto «' . $this->bankAccount->getLabel() . '» ist bereits ' . $until . ' (Saldo ' . AmountFormat::of($this->balance) . ').';
        }
        $count = $this->newMovements === 1 ? '1 neue Bewegung' : $this->newMovements . ' neue Bewegungen';

        return $count . ', Saldo stimmt (' . $until . ', Saldo ' . AmountFormat::of($this->balance) . ').'
            . ($this->skipped > 0 ? ' ' . $this->skipped . ' vorgemerkte, noch nicht gebuchte Einträge wurden ausgelassen.' : '');
    }
}
