<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * A LEARNED PATTERN of a bank account (ADR-061 decisions 5, 8, 14; stage C2) — what the office
 * decided once for a counterparty, a merchant or a recurring text, kept in the database (no AI,
 * ADR-060 decision 14; the model of creditor's learned values).
 *
 * WHAT a pattern matches: the movements of ITS bank account, of ITS direction, whose
 * `pattern_key` is its `match_key` ({@see keyOf()}: the counterparty's IBAN, else the merchant
 * after «Bezugsort:», else the counterparty's name, else the text without its numbers) — and,
 * when the office narrowed it, only those of an APPROXIMATE AMOUNT (`amount_min` … `amount_max`,
 * ± {@see AMOUNT_TOLERANCE_PERCENT} % of the confirmed movement) and around a DAY OF THE MONTH
 * (± {@see DAY_TOLERANCE} days, across the month end) — the monthly pension credit, not every
 * credit of that sender. One pattern per bank account, direction and key.
 *
 * WHAT it says (`kind`):
 *   - {@see KIND_PRIVATE} — confirmed private: every movement it matches is «privat», the coming
 *     ones too. A link beats it (a document makes a movement «geschäftlich», decision 7);
 *   - {@see KIND_MIXED} — «gemischt»: some are business, some private; its movements are ALWAYS
 *     «zu prüfen», each decided on its own (decision 8);
 *   - {@see KIND_BUSINESS} — nothing private about it: it only carries the PROPOSAL.
 *
 * The PROPOSAL (`contra_account`, `tax_code`): what «Geschäftlich buchen …» last used for a
 * movement of this key — offered the next time, never posted by itself (decision 14).
 *
 * Table `bank_pattern` (`Version20261117170000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_pattern')]
#[ORM\UniqueConstraint(name: BankPattern::UNIQUE_KEY, columns: ['bank_account_id', 'direction', 'match_key'])]
class BankPattern
{
    public const UNIQUE_KEY = 'uniq_bank_pattern_key';

    public const KIND_PRIVATE  = 'private';
    public const KIND_MIXED    = 'mixed';
    public const KIND_BUSINESS = 'business';
    public const KINDS = [self::KIND_PRIVATE, self::KIND_MIXED, self::KIND_BUSINESS];

    public const KEY_LENGTH   = 80;
    public const LABEL_LENGTH = 140;

    /** «Approximate amount»: the confirmed movement's amount ± this many percent. */
    public const AMOUNT_TOLERANCE_PERCENT = 10;
    /** «Day of month»: the confirmed movement's day ± this many days, across the month end. */
    public const DAY_TOLERANCE = 3;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(name: 'bank_account_id', nullable: false)]
    private BankAccount $bankAccount;

    /** {@see BankMovement::DEBIT} | {@see BankMovement::CREDIT}. */
    #[ORM\Column(length: 6)]
    private string $direction;

    /** {@see keyOf()} — what `bank_movement.pattern_key` is compared with. */
    #[ORM\Column(name: 'match_key', length: self::KEY_LENGTH)]
    private string $matchKey;

    /** The words the screen names the pattern by («Beispiel Markt Luzern») — bank text, escaped on output. */
    #[ORM\Column(length: self::LABEL_LENGTH)]
    private string $label;

    /** {@see self::KINDS}. */
    #[ORM\Column(length: 10)]
    private string $kind;

    #[ORM\Column(name: 'amount_min', type: MoneyType::NAME, nullable: true)]
    private ?Money $amountMin = null;

    #[ORM\Column(name: 'amount_max', type: MoneyType::NAME, nullable: true)]
    private ?Money $amountMax = null;

    /** 1–31, or null = any day. */
    #[ORM\Column(name: 'day_of_month', type: Types::SMALLINT, nullable: true)]
    private ?int $dayOfMonth = null;

    /** The proposal: the contra account «Geschäftlich buchen …» last used (an account number). */
    #[ORM\Column(name: 'contra_account', length: Account::NUMBER_LENGTH, nullable: true)]
    private ?string $contraAccount = null;

    /** The proposal: the tax code last used; null = «ohne MWST». */
    #[ORM\Column(name: 'tax_code', length: JournalLine::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode = null;

    #[ORM\Column(name: 'confirmed_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $confirmedBy;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $confirmedAt;

    public function __construct(BankAccount $bankAccount, string $direction, string $matchKey, string $label, string $kind, string $confirmedBy, \DateTimeImmutable $confirmedAt)
    {
        if ($matchKey === '' || mb_strlen($matchKey) > self::KEY_LENGTH) {
            throw new \LogicException('A pattern needs a key of 1-' . self::KEY_LENGTH . ' characters');
        }
        $this->bankAccount = $bankAccount;
        $this->direction   = $direction === BankMovement::CREDIT ? BankMovement::CREDIT : BankMovement::DEBIT;
        $this->matchKey    = $matchKey;
        $this->label       = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $label)), 0, self::LABEL_LENGTH);
        $this->decide($kind, $confirmedBy, $confirmedAt);
    }

    /**
     * The key a movement is matched by — pure, from the file's own data: the counterparty's IBAN;
     * else the merchant after «Bezugsort:» (a card or TWINT purchase names nobody else); else the
     * counterparty's name; else the text without its numbers and dates (a bank charge). '' when
     * nothing is left. Lower-cased, whitespace collapsed, at most {@see KEY_LENGTH} characters.
     */
    public static function keyOf(string $counterpartyIban, string $counterpartyName, string $text): string
    {
        $norm = static fn(string $value): string => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
        $iban = strtoupper((string) preg_replace('/\s+/', '', $counterpartyIban));
        if ($iban !== '') {
            return self::cut('iban:', $iban);
        }
        $merchant = self::merchantOf($text);
        if ($merchant !== '') {
            return self::cut('merchant:', $norm($merchant));
        }
        if ($norm($counterpartyName) !== '') {
            return self::cut('name:', $norm($counterpartyName));
        }
        $plain = $norm((string) preg_replace('/[\d.,:;\/\-]+/u', ' ', $text));

        return $plain === '' ? '' : self::cut('text:', $plain);
    }

    /** The merchant a card or TWINT purchase names after «Bezugsort:» — up to the next comma; '' without one. */
    /**
     * A pattern's NAME from the words it was learned from (owner 2026-11-19: the question named a
     * pattern «Einkauf TWINT DIGITEC GALAXUS, ZURICH 26.10.26 / 14:56:07» — one movement's raw text):
     * dates (26.10.26, 26.10.2026) and times (14:56, 14:56:07) are cut, with the «/» between them,
     * whitespace collapsed, trailing separators trimmed. Nothing else is touched — the words stay as
     * the bank wrote them. Falls back to the input when nothing would be left.
     */
    public static function nameOf(string $label): string
    {
        $name = (string) preg_replace('~\b\d{1,2}\.\d{1,2}\.(?:\d{4}|\d{2})\b|\b\d{1,2}:\d{2}(?::\d{2})?\b~u', ' ', $label);
        $name = (string) preg_replace('~(?:\s*/\s*)+(?=\s|$)~u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " \t,;/-·");

        return $name !== '' ? $name : trim($label);
    }

    public static function merchantOf(string $text): string
    {
        return preg_match('/Bezugsort:\s*([^,;\r\n]+)/iu', $text, $m) === 1 ? trim($m[1]) : '';
    }

    private static function cut(string $prefix, string $value): string
    {
        return $prefix . mb_substr($value, 0, self::KEY_LENGTH - strlen($prefix));
    }

    /** Whether $day (1–31) lies within {@see DAY_TOLERANCE} days of $of, across the month end. */
    public static function dayNear(int $day, int $of): bool
    {
        $distance = abs($day - $of);

        return min($distance, 31 - $distance) <= self::DAY_TOLERANCE;
    }

    /** Whether the pattern (its key and direction given) takes a movement of $amount booked on $date — the narrowing. */
    public function takes(Money $amount, \DateTimeImmutable $date): bool
    {
        if ($this->amountMin !== null && $this->amountMax !== null && ($amount->lessThan($this->amountMin) || $amount->greaterThan($this->amountMax))) {
            return false;
        }

        return $this->dayOfMonth === null || self::dayNear((int) $date->format('j'), $this->dayOfMonth);
    }

    /** The office decided what the pattern says. */
    public function decide(string $kind, string $by, \DateTimeImmutable $at): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \LogicException("Unknown pattern kind '{$kind}'");
        }
        $this->kind        = $kind;
        $this->confirmedBy = mb_substr($by, 0, JournalEntry::ACTOR_LENGTH);
        $this->confirmedAt = $at;
    }

    /**
     * Narrow the pattern to the approximate amount and / or the day of month of the movement it
     * was confirmed on; null lifts that narrowing.
     */
    public function narrow(?Money $amount, ?int $dayOfMonth): void
    {
        if ($amount === null) {
            $this->amountMin = $this->amountMax = null;
        } else {
            $spread          = $amount->multiplyByRatio(self::AMOUNT_TOLERANCE_PERCENT, 100);
            $this->amountMin = $amount->subtract($spread);
            $this->amountMax = $amount->add($spread);
        }
        $this->dayOfMonth = $dayOfMonth === null ? null : max(1, min(31, $dayOfMonth));
    }

    /** What «Geschäftlich buchen …» used — offered the next time. */
    public function propose(string $contraAccount, ?string $taxCode): void
    {
        $this->contraAccount = $contraAccount;
        $this->taxCode       = $taxCode;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getDirection(): string { return $this->direction; }
    public function getMatchKey(): string { return $this->matchKey; }
    /** The name the screens show — cleaned on READ too ({@see nameOf()}), so a pattern learned before 2026-11-19 reads well without a migration. */
    public function getLabel(): string { return self::nameOf($this->label); }
    public function getKind(): string { return $this->kind; }
    public function isPrivate(): bool { return $this->kind === self::KIND_PRIVATE; }
    public function isMixed(): bool { return $this->kind === self::KIND_MIXED; }
    public function getAmountMin(): ?Money { return $this->amountMin; }
    public function getAmountMax(): ?Money { return $this->amountMax; }
    public function getDayOfMonth(): ?int { return $this->dayOfMonth; }
    public function getContraAccount(): ?string { return $this->contraAccount; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function getConfirmedBy(): string { return $this->confirmedBy; }
    public function getConfirmedAt(): \DateTimeImmutable { return $this->confirmedAt; }
}
