<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Validators\DunningLevelValidator;
use Z77\Module\Debtor\Validators\PaymentTargetValidator;
use Z77\Module\Debtor\Validators\PaymentTermsValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Validation\EntityValidator;

/**
 * The write side of debtor's file-based master data — payment terms,
 * payment targets and dunning levels — and the few rules that must hold no
 * matter which screen or script writes (ADR-043 decision 19, the
 * `VatMasterData` model):
 *
 *   - a row is never deleted, only deactivated: there is no method for it,
 *     and none of the three screens offers one;
 *   - the `code` of an existing row is immutable — profiles, invoices and
 *     notices carry it ({@see MasterDataCodeChangedException});
 *   - every write runs the row's validator, so a writer that is NOT the
 *     backend (an import, a project script) is bound to the same rules
 *     ({@see InvalidMasterDataException} carries it back).
 *
 * One class for the three, as `VatMasterData` holds codes and rates
 * together: they share every rule and differ only in their validator.
 *
 * The payment target's validator asks module-financial whether its ledger
 * account may be posted to — softly, because financial is only `suggest`ed
 * ({@see LedgerAccountCheck}).
 */
final class DebtorMasterData
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * @throws MasterDataCodeChangedException the code of an existing row differs from what is stored
     * @throws InvalidMasterDataException     the validator refused the row
     */
    public function saveTerms(PaymentTerms $terms): void
    {
        $this->assertCodeUnchanged($terms, 'payment terms');
        $this->assertValid(new PaymentTermsValidator($terms, $this->em->getRepository(PaymentTerms::class)));

        $this->em->persist($terms);
        $this->em->flush();
    }

    /**
     * @throws MasterDataCodeChangedException
     * @throws InvalidMasterDataException
     */
    public function saveTarget(PaymentTarget $target): void
    {
        $this->assertCodeUnchanged($target, 'payment target');
        $this->assertValid(new PaymentTargetValidator(
            $target,
            $this->em->getRepository(PaymentTarget::class),
            new LedgerAccountCheck($this->em),
        ));

        $this->em->persist($target);
        $this->em->flush();
    }

    /**
     * @throws MasterDataCodeChangedException
     * @throws InvalidMasterDataException
     */
    public function saveLevel(DunningLevel $level): void
    {
        $this->assertCodeUnchanged($level, 'dunning level');
        $this->assertValid(new DunningLevelValidator($level, $this->em->getRepository(DunningLevel::class)));

        $this->em->persist($level);
        $this->em->flush();
    }

    /**
     * Deactivate / reactivate — the only «removal» there is (ADR-043
     * decision 19), and a PURE STATE CHANGE: the code is checked, the row is
     * written, the field validator does NOT run (the `VatMasterData::setActive()`
     * model, review 2026-09-22).
     *
     * Why: a row must be switchable off EXACTLY when it has become invalid,
     * and that is the normal reason to switch it off. A payment target whose
     * ledger account was deactivated in the bookkeeping, or a row whose
     * document text stands in a language the installation has since dropped,
     * would otherwise be locked in the active state by its own validator —
     * the rule meant for NEW input would forbid the cleanup. The full
     * validator runs where new input arrives: on a form save
     * ({@see saveTerms()}, {@see saveTarget()}, {@see saveLevel()}).
     */
    public function setTermsActive(PaymentTerms $terms, bool $active): void
    {
        $this->setActive($terms, $active, 'payment terms');
    }

    public function setTargetActive(PaymentTarget $target, bool $active): void
    {
        $this->setActive($target, $active, 'payment target');
    }

    public function setLevelActive(DunningLevel $level, bool $active): void
    {
        $this->setActive($level, $active, 'dunning level');
    }

    /** @throws MasterDataCodeChangedException the row on disk carries another code (a hand-edited file) */
    private function setActive(PaymentTerms|PaymentTarget|DunningLevel $row, bool $active, string $entity): void
    {
        $this->assertCodeUnchanged($row, $entity);
        $row->setActive($active);

        $this->em->persist($row);
        $this->em->flush();
    }

    /**
     * For an existing row the STORED code is compared against what is about
     * to be written; a change is refused. A file-based row is not «managed»
     * the way a Doctrine entity is, so the comparison reads the row again.
     *
     * @throws MasterDataCodeChangedException
     */
    private function assertCodeUnchanged(PaymentTerms|PaymentTarget|DunningLevel $row, string $entity): void
    {
        if ($row->getId() === null) {
            return;
        }

        $stored = $this->em->getRepository($row::class)->find($row->getId());
        if ($stored !== null && $stored->getCode() !== $row->getCode()) {
            throw new MasterDataCodeChangedException($entity, $stored->getCode(), $row->getCode());
        }
    }

    /** @throws InvalidMasterDataException */
    private function assertValid(EntityValidator $validator): void
    {
        if (!$validator->isValid()) {
            throw new InvalidMasterDataException($validator);
        }
    }
}
