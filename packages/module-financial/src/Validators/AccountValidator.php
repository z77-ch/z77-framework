<?php

namespace Z77\Module\Financial\Validators;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates an {@see Account}. The rules, each for a reason:
 *
 *   - number: required, digits only, at most {@see Account::NUMBER_LENGTH},
 *     unique — accounts are named by number in settings and postings;
 *   - name required; type one of the five {@see AccountType}s;
 *   - parent: a GROUP (non-postable) — a postable account collects no
 *     children, so a report never has to split an account's own lines from
 *     its children's; never the account itself or one of its descendants
 *     (no cycle — the reports walk the chain upwards);
 *   - postable: a group that HAS children cannot become postable, for the
 *     same reason from the other side;
 *   - the journal locks (FIN-TYPE-001, owner 2026-09-22), checked against
 *     the STORED state the service hands in: the type cannot change once a
 *     journal line of the account lies in a `closed` period (the reports
 *     read the type at runtime — a change would move closed years between
 *     balance sheet and income statement; the correction is a new account
 *     and a manual transfer), and a postable account cannot become a group
 *     once ANY journal line references it (a group never carries lines).
 *     Name and active stay free.
 *
 * No rule ties the number to the parent's number or the type to the
 * parent's type: the KMU chart mixes types inside one class (class 2
 * holds liabilities and equity, 7 and 8 revenue and expense), and a chart
 * that numbers differently is still a chart.
 *
 * Without a repository only what the account itself shows is checked
 * (format, parent chain); uniqueness and «has children» need the database,
 * the journal locks need the line repository AND the stored state.
 */
class AccountValidator extends EntityValidator
{
    /** German refusal of a type change (FIN-TYPE-001) — the validator's message and the edit form's hint. */
    public const TYPE_LOCKED = 'Die Kontoart ist gesperrt: auf dem Konto liegen Buchungen in einer abgeschlossenen Periode. '
        . 'Für eine andere Kontoart ein neues Konto anlegen und den Saldo mit einer manuellen Umbuchung übertragen.';

    /** German refusal of «becomes a group» (FIN-TYPE-001). */
    public const POSTABLE_LOCKED = 'Das Konto hat Buchungen — es kann keine Gruppe werden. Eine Gruppe trägt nie Buchungen.';

    /**
     * @param array{type: string, postable: bool}|null $stored the account's STORED type and
     *        postable — the managed entity before the change, or the row read under
     *        `AccountRepository::lockForUpdate()`; with $lines it enables the journal locks
     */
    public function __construct(
        Account $account,
        private ?AccountRepository $accounts = null,
        private ?JournalLineRepository $lines = null,
        private ?array $stored = null,
    ) {
        parent::__construct($account);
    }

    /** A conflict only the database could see (the unique number under a race). */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validateNumber(string $number): void
    {
        $this->validate('number', 'Kontonummer', $number)
            ->notEmpty()
            ->maxLength(Account::NUMBER_LENGTH);

        if ($this->hasFieldError('number')) {
            return;
        }
        if (!preg_match('/^[0-9]+$/', $number)) {
            $this->addFieldError('number', 'Kontonummer besteht nur aus Ziffern (z.B. 1020).');
            return;
        }
        $existing = $this->accounts?->findOneBy(['number' => $number]);
        if ($existing !== null && $existing->getId() !== $this->entity->getId()) {
            $this->addFieldError('number', 'Konto ' . $number . ' gibt es bereits («' . $existing->getName() . '»).');
        }
    }

    public function validateName(string $name): void
    {
        $this->validate('name', 'Bezeichnung', $name)
            ->notEmpty()
            ->maxLength(120);
    }

    public function validateType(string $type): void
    {
        $this->validate('type', 'Kontoart', $type)->notEmpty();

        if (!$this->hasFieldError('type') && AccountType::tryFrom($type) === null) {
            $this->addFieldError('type', 'Unbekannte Kontoart: ' . $type);
            return;
        }

        $id = $this->entity->getId();
        if ($id !== null && $this->lines !== null && $this->stored !== null && $type !== $this->stored['type']
            && $this->lines->accountHasLinesInClosedPeriod($id)) {
            $this->addFieldError('type', self::TYPE_LOCKED);
        }
    }

    public function validateParent(?Account $parent): void
    {
        if ($parent === null) {
            return;
        }
        if ($parent->isPostable()) {
            $this->addFieldError('parent', 'Übergeordnet kann nur eine Gruppe sein — «' . $parent->label() . '» ist ein bebuchbares Konto.');
            return;
        }

        // Walk the chain upwards: meeting this account again is a cycle. The
        // visited set also ends a walk through a cycle already in the data.
        $visited = [];
        for ($node = $parent; $node !== null; $node = $node->getParent()) {
            if ($this->isThisAccount($node)) {
                $this->addFieldError('parent', 'Ein Konto kann nicht unter sich selbst oder einem eigenen Unterkonto stehen.');
                return;
            }
            $key = $node->getId() ?? spl_object_id($node);
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
        }
    }

    public function validatePostable(bool $postable): void
    {
        $id = $this->entity->getId();
        if ($id === null) {
            return;
        }
        if (!$postable) {
            if ($this->lines !== null && ($this->stored['postable'] ?? false) && $this->lines->accountHasLines($id)) {
                $this->addFieldError('postable', self::POSTABLE_LOCKED);
            }
            return;
        }
        if ($this->accounts === null) {
            return;
        }
        if ($this->accounts->findOneBy(['parent' => $id]) !== null) {
            $this->addFieldError('postable', 'Das Konto hat Unterkonten — eine Gruppe ist nicht bebuchbar.');
        }
    }

    /** The account being validated — by object, or by id for the service's detached draft. */
    private function isThisAccount(Account $node): bool
    {
        return $node === $this->entity
            || ($node->getId() !== null && $node->getId() === $this->entity->getId());
    }
}
