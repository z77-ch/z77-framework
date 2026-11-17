<?php
/**
 * The bank account selection of «Finanzen › Bankkonto» — at the TOP OF THE RAIL (body section
 * `railSelect`, ADR-033 rev.: a choice that holds for the whole entry stands above the menu). The
 * look and the mechanics of the fiscal-year selection (`fiscalYearSelect`): a CSS-only dropdown
 * (`<details>` with the `.be-shell-add` picker anatomy), every account a plain link with
 * `?account=<code>`. No JavaScript (Rule 7). Added only when more than one bank account takes
 * statements.
 *
 * @var list<\Z77\Module\Financial\Entities\BankAccount> $accounts
 * @var \Z77\Module\Financial\Entities\BankAccount|null $account  the selected one
 * @var callable(string): string $accountHref
 */
if (empty($accounts) || !isset($account)) { return; }
?>
<details class="be-shell-add be-shell-add--select">
    <summary class="be-btn be-btn--ghost" aria-label="Bankkonto wählen">
        <span class="be-btn__label"><span class="be-shell-select__value"><?= e($account->getLabel()) ?></span></span>
        <svg class="be-icon be-shell-add__chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </summary>
    <nav class="be-shell-add__panel" aria-label="Bankkonten">
        <?php foreach ($accounts as $candidate): ?>
        <a class="be-shell-add__item" href="<?= e($accountHref($candidate->getCode())) ?>"<?= $candidate->getCode() === $account->getCode() ? ' aria-current="true"' : '' ?>><?= e($candidate->getLabel()) ?></a>
        <?php endforeach; ?>
    </nav>
</details>
