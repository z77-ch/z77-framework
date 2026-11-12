<?php

namespace Z77\Module\Receipt\Services;

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
 */
final class PaymentMethodService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** @return list<PaymentMethod> the active payment methods in choice order (position, id) */
    public function active(): array
    {
        return $this->methods()->activeInOrder();
    }

    /** @return list<PaymentMethod> every payment method in choice order, the inactive ones included */
    public function all(): array
    {
        return $this->methods()->allInOrder();
    }

    public function find(string $code): ?PaymentMethod
    {
        return $this->methods()->findByCode($code);
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
        $this->em->persist($method);
        $this->em->flush();
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
