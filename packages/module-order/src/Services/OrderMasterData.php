<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Module\Order\Validators\OrderStatusValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of module-order's file-based master data — the order statuses (plan §4.2) — and
 * the rules that hold no matter which screen or script writes (the `DebtorMasterData` model,
 * ADR-043 decision 19):
 *
 *   - a row is never deleted, only deactivated: there is no method for it, and the screen offers
 *     none — orders reference the code;
 *   - the `code` of an existing row is immutable ({@see OrderStatusCodeChangedException});
 *   - every save runs {@see OrderStatusValidator} (phase, position, one active default per phase),
 *     so a writer that is NOT the backend (an import, a project script) is bound to the same rules.
 *
 * {@see setActive()} is a PURE STATE CHANGE like debtor's — the field validator does not run, so a
 * row that has become invalid can always be switched off. Switching ON is checked against the
 * cross-row rule only (one active default per phase): an activation that broke it would make the
 * status an action sets ambiguous.
 */
final class OrderMasterData
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * @throws OrderStatusCodeChangedException the code of an existing row differs from what is stored
     * @throws InvalidOrderStatusException     the validator refused the row
     */
    public function save(OrderStatus $status): void
    {
        $this->assertCodeUnchanged($status);
        $validator = new OrderStatusValidator($status, $this->statuses());
        if (!$validator->isValid()) {
            throw new InvalidOrderStatusException($validator);
        }
        // A switch the phase does not know is not stored (a status moved to another phase).
        $status->setStart($status->isStart());
        $status->setPhaseDefault($status->isPhaseDefault());

        // Setting a switch takes it from the row that held it (owner 2026-10-16), in the same flush.
        $takeovers = OrderStatuses::takeovers($status, $this->statuses()->findAll());
        foreach ($takeovers['start'] as $other) {
            $other->setStart(false);
            $this->em->persist($other);
        }
        foreach ($takeovers['default'] as $other) {
            $other->setPhaseDefault(false);
            $this->em->persist($other);
        }
        $this->em->persist($status);
        $this->em->flush();
    }

    /**
     * Deactivate / reactivate — the only «removal» there is. An order that sits in a deactivated
     * status keeps it; the status is no longer offered as a target.
     *
     * Switching a row ON does not take a switch from another: while it was inactive another row
     * took over, and that one keeps it — the reactivated row drops the switch (owner 2026-10-16:
     * only SETTING a switch takes it over).
     *
     * @throws OrderStatusCodeChangedException the row on disk carries another code (a hand-edited file)
     */
    public function setActive(OrderStatus $status, bool $active): void
    {
        $this->assertCodeUnchanged($status);
        $status->setActive($active);
        if ($active) {
            $takeovers = OrderStatuses::takeovers($status, $this->statuses()->findAll());
            if ($takeovers['start'] !== []) {
                $status->setStart(false);
            }
            if ($takeovers['default'] !== []) {
                $status->setPhaseDefault(false);
            }
        }

        $this->em->persist($status);
        $this->em->flush();
    }

    /** @throws OrderStatusCodeChangedException */
    private function assertCodeUnchanged(OrderStatus $status): void
    {
        if ($status->getId() === null) {
            return;
        }
        $stored = $this->statuses()->find($status->getId());
        if ($stored !== null && $stored->getCode() !== $status->getCode()) {
            throw new OrderStatusCodeChangedException($stored->getCode(), $status->getCode());
        }
    }

    private function statuses(): OrderStatusRepository
    {
        return $this->em->getRepository(OrderStatus::class);
    }
}
