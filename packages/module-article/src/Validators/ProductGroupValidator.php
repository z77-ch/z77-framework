<?php

namespace Z77\Module\Article\Validators;

use Z77\Module\Article\Entities\AbstractProduct;
use Z77\Module\Article\Entities\ProductGroup;
use Z77\Module\Article\Services\ProductGroupDraft;
use Z77\Module\Article\Services\ProductGroupTree;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Validation\EntityValidator;

/**
 * The rules of ONE product-group save, over the {@see ProductGroupDraft} — before anything managed
 * is touched (ADR-039 decision 9). Pure: the tree is a {@see ProductGroupTree} (all groups, read
 * once), the two lookups that need other modules are closures the service builds, so the harness
 * checks every rule offline (`tests/module-article.php` G):
 *
 *   - name required, at most 80, unique among its SIBLINGS (the same name under another parent is
 *     fine — «Beratung» may exist under two business areas);
 *   - parent: 0 = a root, else a group that exists and is neither the group itself nor below it
 *     (a move into its own subtree would cut the branch off the tree);
 *   - default tax code: optional; set, it is a known code and ACTIVE — or the one the group
 *     already carries (the reference rule, ADR-043 decision 19, like the article's own field);
 *   - default revenue account: optional; set, digits and the SOFT account check the article uses
 *     (`ArticleService::accountProblem()`: postable, «Ertrag», «auf Belegen wählbar»).
 *
 * `$taxCode(string): ?TaxCode` and `$accountProblem(string): ?string` are the article
 * validator's closures (null = not asked / not checkable).
 */
class ProductGroupValidator extends EntityValidator
{
    private const ACCOUNT_PATTERN = '/^[0-9]{1,' . AbstractProduct::ACCOUNT_LENGTH . '}$/';

    /**
     * @param ProductGroup|null $existing the group being edited (null = new)
     * @param \Closure(string): ?TaxCode|null $taxCode
     * @param \Closure(string): ?string|null  $accountProblem
     */
    public function __construct(
        private readonly ProductGroupDraft $draft,
        private readonly ?ProductGroupTree $tree = null,
        private readonly ?ProductGroup $existing = null,
        private readonly ?\Closure $taxCode = null,
        private readonly ?\Closure $accountProblem = null,
    ) {
        parent::__construct($draft);
    }

    public function validateName(string $name): void
    {
        $this->validate('name', 'Name', $name)->notEmpty()->maxLength(ProductGroup::NAME_LENGTH);
        if ($this->hasFieldError('name') || $this->tree === null) {
            return;
        }
        $own = $this->existing?->getId();
        foreach ($this->tree->rows() as $row) {
            $group = $row['group'];
            if ($group->getId() !== $own && (int) $group->getParentId() === $this->draft->parentId
                && mb_strtolower($group->getName()) === mb_strtolower($name)) {
                $this->addFieldError('name', 'Eine Warengruppe «' . $group->getName() . '» gibt es auf dieser Ebene schon.');
                return;
            }
        }
    }

    public function validateParentId(int $parentId): void
    {
        if ($parentId === 0 || $this->tree === null) {
            return;
        }
        if ($this->tree->find($parentId) === null) {
            $this->addFieldError('parent_id', 'Die übergeordnete Warengruppe gibt es nicht mehr — bitte neu wählen.');
            return;
        }
        $own = $this->existing?->getId();
        if ($own !== null && $this->tree->isWithin($parentId, $own)) {
            $this->addFieldError('parent_id', 'Eine Warengruppe kann nicht unter sich selbst oder eine ihrer Untergruppen gehängt werden.');
        }
    }

    public function validateDefaultTaxCode(string $code): void
    {
        if ($code === '') {
            return;
        }
        $this->validate('default_tax_code', 'MWST-Code', $code)->maxLength(AbstractProduct::TAX_CODE_LENGTH);
        if ($this->hasFieldError('default_tax_code') || $this->taxCode === null) {
            return;
        }
        $row = ($this->taxCode)($code);
        if ($row === null) {
            $this->addFieldError('default_tax_code', 'Den MWST-Code «' . $code . '» gibt es nicht.');
            return;
        }
        if (!$row->isActive() && TaxCode::normalizeCode($this->existing?->getDefaultTaxCode() ?? '') !== $row->getCode()) {
            $this->addFieldError('default_tax_code', 'Der MWST-Code «' . $code . '» ist inaktiv — nur eine Warengruppe, die ihn schon vorgibt, behält ihn.');
        }
    }

    public function validateDefaultRevenueAccount(string $account): void
    {
        if ($account === '') {
            return;
        }
        if (!preg_match(self::ACCOUNT_PATTERN, $account)) {
            $this->addFieldError('default_revenue_account', 'Das Ertragskonto ist eine Kontonummer (nur Ziffern, z.B. 3200).');
            return;
        }
        $problem = $this->accountProblem !== null ? ($this->accountProblem)($account) : null;
        if ($problem !== null) {
            $this->addFieldError('default_revenue_account', $problem);
        }
    }
}
