<?php

namespace Z77\Module\Financial\Validators;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Repositories\AccountRepository;
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
 *     same reason from the other side.
 *
 * No rule ties the number to the parent's number or the type to the
 * parent's type: the KMU chart mixes types inside one class (class 2
 * holds liabilities and equity, 7 and 8 revenue and expense), and a chart
 * that numbers differently is still a chart.
 *
 * Without a repository only what the account itself shows is checked
 * (format, parent chain); uniqueness and «has children» need the database.
 */
class AccountValidator extends EntityValidator
{
    public function __construct(Account $account, private ?AccountRepository $accounts = null)
    {
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
        if (!$postable || $id === null || $this->accounts === null) {
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
