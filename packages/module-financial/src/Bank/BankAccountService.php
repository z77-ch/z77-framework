<?php

namespace Z77\Module\Financial\Bank;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankStatement;
use Z77\Module\Financial\Repositories\BankAccountRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Validators\BankAccountValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of the master data «Bankkonten» (ADR-060 decisions 3, 8–10) — the one place an
 * IBAN is tied to a ledger account (Rule 2). Rules that hold no matter which screen or script
 * calls:
 *
 *   - never deleted, only deactivated ({@see setActive()});
 *   - the `code` is immutable — payment methods name the bank account by it;
 *   - the IBAN cannot change once a statement of the account was imported (the statements and
 *     their archived files belong to that IBAN) — a new IBAN is a new bank account;
 *   - the currency is the base currency (a foreign-currency account is refused, decision 8); a
 *     new bank account without a currency gets the base currency;
 *   - every change is validated ({@see BankAccountValidator}) on a detached draft first; a
 *     refused change never reaches the managed entity (ADR-039 decision 9).
 *
 * Readers: {@see find()} / {@see all()}; the statement import looks the account up by IBAN in
 * the repository.
 */
final class BankAccountService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    public function find(string $code): ?BankAccount
    {
        return $this->bankAccounts()->findByCode($code);
    }

    /** @return list<BankAccount> every bank account by label, the inactive ones included */
    public function all(): array
    {
        return $this->bankAccounts()->allInOrder();
    }

    /**
     * Stores a NEW bank account.
     *
     * @throws InvalidBankAccountException
     */
    public function save(BankAccount $account): void
    {
        if ($account->getId() !== null) {
            throw new \LogicException('save() takes a NEW bank account — an existing one is changed through update($account, $values)');
        }
        if ($account->getCurrency() === '') {
            $account->setCurrency(LedgerService::baseCurrency());
        }
        $validator = $this->validator($account);
        if (!$validator->isValid()) {
            throw new InvalidBankAccountException($validator, $account);
        }
        $this->em->persist($account);
        $this->flushOrRefuse($account);
    }

    /**
     * Changes an existing bank account: $values (snake_case keys, as a form posts them) are
     * validated on a detached draft and applied to the managed entity only when they pass.
     *
     * @param array<string, mixed> $values
     * @throws InvalidBankAccountException
     */
    public function update(BankAccount $account, array $values): void
    {
        if ($account->getId() === null) {
            throw new \LogicException('update() takes an existing bank account — a new one goes through save()');
        }
        $draft = clone $account;
        $draft->mapFromArray($values);
        $validator = $this->validator($draft);
        $valid     = $validator->isValid();
        if ($draft->getCode() !== $account->getCode()) {
            $validator->flagFieldError('code', 'Das Kürzel «' . $account->getCode() . '» lässt sich nicht ändern — Zahlungsarten verweisen darauf.');
            $valid = false;
        }
        if ($draft->getIban() !== $account->getIban() && $this->em->getRepository(BankStatement::class)->findOneBy(['bankAccount' => $account->getId()]) !== null) {
            $validator->flagFieldError('iban', 'Zu diesem Bankkonto sind Kontoauszüge eingelesen — die IBAN lässt sich nicht mehr ändern. Für eine neue IBAN ein neues Bankkonto anlegen.');
            $valid = false;
        }
        if (!$valid) {
            throw new InvalidBankAccountException($validator, $draft);
        }
        $account->mapFromArray($values);
        $this->em->persist($account);
        $this->flushOrRefuse($account);
    }

    /** Deactivate / reactivate — a pure state change, no field validation. */
    public function setActive(BankAccount $account, bool $active): void
    {
        $account->setActive($active);
        $this->em->persist($account);
        $this->em->flush();
    }

    private function validator(BankAccount $draft): BankAccountValidator
    {
        return new BankAccountValidator($draft, $this->bankAccounts(), $this->em->getRepository(Account::class), LedgerService::baseCurrency());
    }

    /** The flush; a unique key a concurrent save took first becomes the field error the validator would have given. */
    private function flushOrRefuse(BankAccount $account): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            $validator = new BankAccountValidator($account);
            str_contains($e->getMessage(), BankAccount::UNIQUE_IBAN)
                ? $validator->flagFieldError('iban', 'Diese IBAN trägt bereits ein anderes Bankkonto.')
                : $validator->flagFieldError('code', 'Das Kürzel «' . $account->getCode() . '» ist bereits vergeben.');
            throw new InvalidBankAccountException($validator, $account);
        }
    }

    private function bankAccounts(): BankAccountRepository
    {
        return $this->em->getRepository(BankAccount::class);
    }
}
