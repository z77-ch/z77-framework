<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Module\Receipt\Repositories\ReceiptTemplateRepository;
use Z77\Module\Receipt\Validators\ReceiptTemplateValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The receipt templates — master data of the installation (owner decisions E3 / E10, 2026-11-12),
 * the `DebtorMasterData` / `OrderMasterData` model (ADR-043 decision 19); its twin for the credit
 * side is {@see PaymentMethodService}:
 *
 *   - a row is never deleted, only deactivated: there is no method for it — a used template keeps
 *     resolving for the receipts that name it;
 *   - the `code` of an existing row is immutable ({@see MasterDataCodeChangedException});
 *   - every save runs the validator WITH the references (debit account, tax code), so a writer
 *     that is not the backend is bound to the same rules ({@see InvalidMasterDataException}).
 *
 * The phone sees {@see active()} — every paired device sees every active template (E3; tiles per
 * device or person come later, through the gateway's scopes).
 */
final class ReceiptTemplateService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** @return list<ReceiptTemplate> the active templates in tile order (position, id) */
    public function active(): array
    {
        return $this->templates()->activeInOrder();
    }

    /** @return list<ReceiptTemplate> every template in tile order, the inactive ones included */
    public function all(): array
    {
        return $this->templates()->allInOrder();
    }

    public function find(string $code): ?ReceiptTemplate
    {
        return $this->templates()->findByCode($code);
    }

    /**
     * Create or change a template.
     *
     * @throws MasterDataCodeChangedException the code of an existing row differs from the stored one
     * @throws InvalidMasterDataException     the validator refused the row
     */
    public function save(ReceiptTemplate $template): void
    {
        $this->assertCodeUnchanged($template);
        $validator = new ReceiptTemplateValidator($template, $this->templates(), $this->em);
        if (!$validator->isValid()) {
            throw new InvalidMasterDataException($validator);
        }
        $this->em->persist($template);
        $this->em->flush();
    }

    /**
     * Deactivate / reactivate — the only «removal» there is, and a PURE STATE CHANGE: the field
     * validator does not run (the `DebtorMasterData::setActive()` model): a template must be
     * switchable off exactly when it became invalid (its account deactivated in the chart).
     *
     * @throws MasterDataCodeChangedException the row on disk carries another code (a hand-edited file)
     */
    public function setActive(ReceiptTemplate $template, bool $active): void
    {
        $this->assertCodeUnchanged($template);
        $template->setActive($active);
        $this->em->persist($template);
        $this->em->flush();
    }

    private function assertCodeUnchanged(ReceiptTemplate $template): void
    {
        if ($template->getId() === null) {
            return;
        }
        $stored = $this->templates()->find($template->getId());
        if ($stored !== null && $stored->getCode() !== $template->getCode()) {
            throw new MasterDataCodeChangedException('receipt template', $stored->getCode(), $template->getCode());
        }
    }

    private function templates(): ReceiptTemplateRepository
    {
        return $this->em->getRepository(ReceiptTemplate::class);
    }
}
