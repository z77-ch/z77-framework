<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Clean,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Payment\Iban,
    Z77\Shared\Traits\ArrayMappable
;

/**
 * A BANK ACCOUNT of the installation — the master data «Bankkonten» (ADR-060 decisions 3, 9,
 * 10): the ONE place that says which IBAN is which ledger account (Rule 2). The camt.053 import
 * finds the account by the statement's IBAN; a payment method that pays through a bank
 * (module-receipt's «Bezahlt mit») names the bank account by `code` and takes its ledger account
 * from here; debtor's payment targets follow in stage B5.
 *
 * Fields: `code` (unique, `[a-z][a-z0-9-]{1,15}`, immutable — what other records hold), `label`,
 * `iban` (normalised, unique), `currency` (stage 1: the base currency only — a foreign-currency
 * account is refused, ADR-060 decision 8), `ledger_account` (a postable, active account number),
 * and ONE switch, OFF by default (owner decisions 17 and 19, 2026-11-16): `statement_import` —
 * «Kontoauszug einlesen». Only an account with this switch ON accepts a camt.053; OFF means the
 * account is master data only (no upload, no movements stored). The screen says plainly that
 * EVERY movement of the account is stored, private ones included.
 *
 * Never deleted, only deactivated (`active`) — statements, movements and payment methods name it.
 * Written through {@see \Z77\Module\Financial\Bank\BankAccountService}. Table `bank_account`
 * (`Version20261116140000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_account')]
#[ORM\UniqueConstraint(name: BankAccount::UNIQUE_CODE, columns: ['code'])]
#[ORM\UniqueConstraint(name: BankAccount::UNIQUE_IBAN, columns: ['iban'])]
class BankAccount
{
    use ArrayMappable;

    public const UNIQUE_CODE = 'uniq_bank_account_code';
    public const UNIQUE_IBAN = 'uniq_bank_account_iban';

    public const CODE_LENGTH  = 16;
    public const LABEL_LENGTH = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation — what a payment method's `bank_account_code` holds. */
    #[ORM\Column(length: self::CODE_LENGTH)]
    #[Clean('ident')]
    private string $code = '';

    /** German, for lists and choices («Hausbank Geschäftskonto»). */
    #[ORM\Column(length: self::LABEL_LENGTH)]
    #[Clean('text')]
    private string $label = '';

    /** Normalised (upper-case, no spaces) — compared with a statement's `Acct/Id/IBAN`. */
    #[ORM\Column(length: Iban::MAX_LENGTH)]
    #[Clean('text')]
    private string $iban = '';

    /** ISO 4217. Stage 1: the installation's base currency only. */
    #[ORM\Column(length: 3)]
    #[Clean('text')]
    private string $currency = '';

    /** Account number (digits) of the ledger account this bank account is kept on. */
    #[ORM\Column(name: 'ledger_account', length: Account::NUMBER_LENGTH)]
    #[Clean('text')]
    private string $ledgerAccount = '';

    /** «Kontoauszug einlesen» — only then a camt.053 of this account is accepted and stored. */
    #[ORM\Column(name: 'statement_import')]
    #[Clean('bool')]
    private bool $statementImport = false;

    /** False = not offered any more; statements, movements and payment methods keep naming it. */
    #[ORM\Column]
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getIban(): string { return $this->iban; }
    public function getCurrency(): string { return $this->currency; }
    public function getLedgerAccount(): string { return $this->ledgerAccount; }
    public function isStatementImport(): bool { return $this->statementImport; }
    public function isActive(): bool { return $this->active; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function setIban(string $iban): void { $this->iban = Iban::normalize($iban); }
    public function setCurrency(string $currency): void { $this->currency = mb_strtoupper(trim($currency)); }
    public function setLedgerAccount(string $number): void { $this->ledgerAccount = trim($number); }
    public function setStatementImport(bool $on): void { $this->statementImport = $on; }
    public function setActive(bool $active): void { $this->active = $active; }
}
