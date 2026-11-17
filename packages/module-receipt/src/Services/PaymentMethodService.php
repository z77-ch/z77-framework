<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Repositories\PaymentMethodRepository;
use Z77\Module\Receipt\Validators\PaymentMethodValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The payment methods — «Bezahlt mit» (owner decision E10, 2026-11-12), master data of the
 * installation with the same rules as {@see ReceiptTemplateService}: never deleted, only
 * deactivated; the code immutable ({@see MasterDataCodeChangedException}); every save validated
 * with the credit account against the chart ({@see InvalidMasterDataException}). The phone offers
 * {@see active()}.
 *
 * A payment method that names a BANK ACCOUNT (`bank_account_code`, ADR-060 decision 9) takes its
 * credit account from module-financial's master data «Bankkonten»: {@see find()}, {@see all()} and
 * {@see active()} RESOLVE it on every read, so `PaymentMethod::getCreditAccount()` answers the
 * bank account's ledger account as it stands now — nothing is copied into the payment method, and
 * {@see save()} clears the method's own account. Readers therefore go through this service; a
 * method read straight from the repository answers '' for a bank-bound method.
 */
final class PaymentMethodService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** @return list<PaymentMethod> the active payment methods in choice order (position, id) */
    public function active(): array
    {
        return $this->resolved($this->methods()->activeInOrder());
    }

    /** @return list<PaymentMethod> every payment method in choice order, the inactive ones included */
    public function all(): array
    {
        return $this->resolved($this->methods()->allInOrder());
    }

    public function find(string $code): ?PaymentMethod
    {
        $method = $this->methods()->findByCode($code);

        return $method === null ? null : $this->resolved([$method])[0];
    }

    /**
     * @throws MasterDataCodeChangedException
     * @throws InvalidMasterDataException
     */
    public function save(PaymentMethod $method): void
    {
        $this->assertCodeUnchanged($method);
        $validator = new PaymentMethodValidator($method, $this->methods(), $this->em);
        if (!$validator->isValid()) {
            throw new InvalidMasterDataException($validator);
        }
        if ($method->getBankAccountCode() !== '') {
            $method->setCreditAccount('');   // the bank account is the one source of the ledger account (Rule 2)
        }
        $this->em->persist($method);
        $this->em->flush();
        $this->resolved([$method]);
    }

    /**
     * Deactivate / reactivate — a pure state change, no field validation (see
     * {@see ReceiptTemplateService::setActive()}).
     *
     * @throws MasterDataCodeChangedException
     */
    public function setActive(PaymentMethod $method, bool $active): void
    {
        $this->assertCodeUnchanged($method);
        $method->setActive($active);
        $this->em->persist($method);
        $this->em->flush();
        $this->resolved([$method]);
    }

    /**
     * Gives every bank-bound method the ledger account of its bank account, read now.
     *
     * @param list<PaymentMethod> $methods
     * @return list<PaymentMethod> the same objects
     */
    private function resolved(array $methods): array
    {
        $ledger = [];
        foreach ($methods as $method) {
            $code = $method->getBankAccountCode();
            if ($code === '') {
                continue;
            }
            if (!array_key_exists($code, $ledger)) {
                $ledger[$code] = $this->em->getRepository(BankAccount::class)->findByCode($code)?->getLedgerAccount();
            }
            $method->resolveBankLedgerAccount($ledger[$code]);
        }

        return $methods;
    }

    private function assertCodeUnchanged(PaymentMethod $method): void
    {
        if ($method->getId() === null) {
            return;
        }
        $stored = $this->methods()->find($method->getId());
        if ($stored !== null && $stored->getCode() !== $method->getCode()) {
            throw new MasterDataCodeChangedException('payment method', $stored->getCode(), $method->getCode());
        }
    }

    private function methods(): PaymentMethodRepository
    {
        return $this->em->getRepository(PaymentMethod::class);
    }
}
