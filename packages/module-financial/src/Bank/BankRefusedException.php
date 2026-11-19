<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Services\FinancialException;

/**
 * The bank account service refused a file, a statement or a link. `reason` is
 * a stable code, `getMessage()` German and says what to do (the model of debtor's
 * `BankImportRefusedException`). The message never carries bank data beyond what the user just
 * handed in (an IBAN, a date) — it may be shown, it is not logged.
 */
final class BankRefusedException extends FinancialException
{
    public const NOT_XML            = 'not-xml';
    public const NOT_CAMT053        = 'not-camt053';
    /** The statement lacks what the import relies on: a booked balance, a booking date, balances that add up. */
    public const INCOMPLETE         = 'incomplete';
    public const DUPLICATE_FILE     = 'duplicate-file';
    public const DUPLICATE_MESSAGE  = 'duplicate-message';
    /** A statement names an IBAN that is an INACTIVE bank account, or one among known accounts that is none. */
    public const ACCOUNT_UNKNOWN    = 'account-unknown';
    /**
     * NO statement of the file belongs to a bank account of this installation (a bank's ZIP carries a
     * statement for every account of the contract) — the upload skips the file and names the
     * accounts in one neutral line; `subjects` carries their IBANs (owner 2026-11-18).
     */
    public const NOT_BANK_ACCOUNT   = 'not-bank-account';
    /** The bank account's switch «Kontoauszug einlesen» is off. */
    public const IMPORT_OFF         = 'import-off';
    public const FOREIGN_CURRENCY   = 'foreign-currency';
    public const ARCHIVE            = 'archive';
    /** The file starts after the last stored day — it must overlap it (owner 2026-11-18; a start the day after is refused too). */
    public const NO_OVERLAP         = 'no-overlap';
    /** The file overlaps what is stored, and its balance at the covered end is not the stored one. */
    public const CHAIN_MISMATCH     = 'chain-mismatch';
    /** The file brings movements from before the first statement of the account. */
    public const BEFORE_START       = 'before-start';
    /** The movement a link names does not exist (any more). */
    public const LINK_GONE          = 'link-gone';
    /** The movement is already linked — one link per movement (ADR-061 decision 9, stage C1). */
    public const LINK_TAKEN         = 'link-taken';
    /** The owner already holds a movement — one movement per owner (stage C1). */
    public const LINK_OWNER         = 'link-owner';
    /** The link would not take the movement's full amount. */
    public const LINK_AMOUNT        = 'link-amount';
    /** The movement is «geschäftlich» (it has a link) — it cannot be set «privat» or «zu prüfen» by hand (stage C2). */
    public const STATE_BUSINESS     = 'state-business';
    /** A private pattern takes the movement, and the question of decision 8 (BankMovementStates::question()) was not answered (ADR-061 decision 8). */
    public const PATTERN_QUESTION   = 'pattern-question';
    /** No private pattern takes the movement — there is nothing to lift or to answer. */
    public const NO_PATTERN         = 'no-pattern';
    /** The bank account is not «gemischt geführt» — nothing of it can be private (owner 2026-11-19). */
    public const NOT_MIXED          = 'not-mixed';
    /** Another module claims the movement (`bankMovementClaims`) — it is that module's to link and cannot be set «privat». */
    public const CLAIMED            = 'claimed';

    /** The movement is information only: its bank account is not bank-driven on its day (ADR-061 decision 1, stage C4). */
    public const NOT_DRIVEN         = 'not-driven';
    /** The anchor cannot be confirmed (nothing to confirm, already confirmed, the statement does not reach the day). */
    public const ANCHOR             = 'anchor';

    /** The refusal of a decision about a movement that is information only. */
    public static function notDriven(\Z77\Module\Financial\Entities\BankMovement $movement): self
    {
        $account = $movement->getBankAccount();
        $from    = $account->getReconcileFrom();

        return new self(self::NOT_DRIVEN, match (true) {
            !$account->isStatementImport() => 'Für das Bankkonto «' . $account->getLabel() . '» ist «Kontoauszug einlesen» ausgeschaltet — seine Bewegungen sind nur Auskunft.',
            $from === null                 => 'Für das Bankkonto «' . $account->getLabel() . '» ist kein «Abgleich ab» gesetzt — seine Bewegungen sind nur Auskunft. Das Datum unter Stammdaten › Finanzen › Bankkonten setzen.',
            default                        => 'Die Bewegung vom ' . $movement->getBookingDate()->format('d.m.Y') . ' liegt vor «Abgleich ab» (' . $from->format('d.m.Y') . ') — sie ist nur Auskunft.',
        });
    }

    /** @param list<string> $subjects what the refusal is about, for a caller that sums refusals up (NOT_BANK_ACCOUNT: the IBANs) */
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null, public readonly array $subjects = [])
    {
        parent::__construct($message, 0, $previous);
    }
}
