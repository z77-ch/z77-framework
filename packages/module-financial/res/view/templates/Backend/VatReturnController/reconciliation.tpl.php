<?php
/**
 * One VAT return — tab «Abstimmung» (ADR-041 addendum point 6, review C8): the accounts as the
 * CONTROL of the return, movements of the period, never balances. Three cards:
 *
 *   - «Umsatz»: the revenue accounts (a disclosure, one row per account), «Formular: Ziffer 200
 *     abzüglich Ziffer 235», the «Unterschied», «davon erklärt» with each named item (lines without
 *     a tax code per revenue account, output-coded lines on another account type), «Nicht erklärt»;
 *   - «Vorsteuer» and «Geschuldete MWST»: each VAT account ↔ its form fields — Buchhaltung (the
 *     account's movement), Formular (the tax the coded lines posted), Nicht erklärt (the gap — it
 *     BLOCKS «MWST buchen», ADR-042 addendum point 1).
 *
 * ONLY «Nicht erklärt» takes the alert colour, and only when it is not 0.00 (the board's rule —
 * the difference itself stays quiet): an existing pill, `badge badge--danger`. Account numbers link
 * to the account statement of the period. No JavaScript (the revenue rows open with the list's
 * checkbox disclosure). NOT a standard list on purpose — fixed sheets.
 *
 * @var \Z77\Module\Financial\VatReturn\VatReturnReconciliation $reconciliation
 * @var \Z77\Module\Financial\VatReturn\VatReturnPreview $preview
 * @var callable $accountUrl  account number → its account statement over the period
 * @var string $periodLabel
 * @var callable $fmt
 */
use Z77\Module\Financial\VatReturn\VatAccountReconciliation;

$r      = $reconciliation;
$rest   = static fn(\Z77\Shared\Money\Money $m): string => $m->isZero()
    ? '<span data-vat-rest="0">' . e($fmt($m)) . '</span>'
    : '<span class="badge badge--danger" data-vat-rest="1">' . e($fmt($m)) . '</span>';
$link   = static fn(string $number, string $name): string => $number === ''
    ? '<span class="be-list__cell--muted">kein Konto hinterlegt</span>'
    : '<a class="be-list__cell--mono" href="' . e($accountUrl($number)) . '">' . e($number) . '</a> ' . e($name);
$owed   = array_values(array_filter($r->vatAccounts, static fn(VatAccountReconciliation $a): bool => $a->role === VatAccountReconciliation::OWED));
$input  = array_values(array_filter($r->vatAccounts, static fn(VatAccountReconciliation $a): bool => $a->role === VatAccountReconciliation::INPUT));
$sum    = static fn(array $accounts, string $what): \Z77\Shared\Money\Money => array_reduce($accounts, static fn($s, VatAccountReconciliation $a) => $s->add($what === 'movement' ? $a->movement : ($what === 'expected' ? $a->expected : $a->difference())), $r->revenueTotal->subtract($r->revenueTotal));
// The input total's field number from the pack (479 in CH) — never typed here.
$inputTotal   = array_values(array_filter($preview->fields, static fn($f): bool => $f->kind === \Z77\Module\Vat\CountryPack\FieldKind::InputTotal))[0]->field ?? '';
$turnoverCols = 'minmax(0, 1fr) 8rem';
$vatCols      = 'minmax(0, 1fr) 7.5rem 7.5rem 7rem';
$vatColsSm    = 'minmax(0, 1fr) 7rem';
?>
<div class="be-list" data-vat-return-view="reconciliation">
    <div class="be-list__section" data-vat-reco="turnover">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Umsatz <small>· <?= e(\Z77\Module\Financial\Ui\VatReturnLayout::periodSpan($preview->period)) ?></small></h2>
        </div>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--disclose be-list__table--tree" style="--be-list-cols: <?= $turnoverCols ?>">
                <div class="be-list__item">
                    <input class="be-list__disclosure-input" type="checkbox" id="vat-reco-revenue">
                    <div class="be-list__row">
                        <label class="be-list__cell be-list__disclosure" for="vat-reco-revenue" title="Konten zeigen"><svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-chevron-right"/></svg></label>
                        <label class="be-list__cell" for="vat-reco-revenue">Ertragskonten laut Buchhaltung</label>
                        <span class="be-list__cell be-list__cell--num" data-vat-revenue-total><?= e($fmt($r->revenueTotal)) ?></span>
                    </div>
                    <?php foreach ($r->revenueAccounts as $line): ?>
                    <div class="be-list__item">
                        <div class="be-list__row">
                            <span class="be-list__cell"></span>
                            <span class="be-list__cell be-list__cell--indent"><?= $link($line->accountNumber, $line->accountName) ?></span>
                            <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->amount)) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell">Formular: Ziffer 200 abzüglich Ziffer 235</span>
                        <span class="be-list__cell be-list__cell--num" data-vat-declared><?= e($fmt($r->declared())) ?></span>
                    </div>
                </div>
                <div class="be-list__item">
                    <div class="be-list__row be-list__row--l1">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell">Unterschied</span>
                        <span class="be-list__cell be-list__cell--num" data-vat-difference><?= e($fmt($r->difference())) ?></span>
                    </div>
                </div>
                <div class="be-list__item">
                    <div class="be-list__row be-list__row--l2">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell">davon erklärt</span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
                <?php if ($r->explained === []): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--muted">nichts</span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php foreach ($r->explained as $line): ?>
                <div class="be-list__item">
                    <div class="be-list__row" data-vat-explained="<?= e($line->kind . ':' . $line->accountNumber) ?>">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($line->label()) ?> · <?= $link($line->accountNumber, $line->accountName) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->amount)) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="be-list__item">
                    <div class="be-list__row be-list__row--total">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell">Nicht erklärt</span>
                        <span class="be-list__cell be-list__cell--num" data-vat-remainder><?= $rest($r->remainder()) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php foreach (['input' => ['Vorsteuer', 'Formular', $input, $inputTotal], 'owed' => ['Geschuldete MWST', 'Formular, gebucht', $owed, null]] as $role => [$title, $formLabel, $accounts, $totalField]): ?>
    <?php if ($accounts === []) { continue; } ?>
    <div class="be-list__section" data-vat-reco="<?= e($role) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: <?= $vatCols ?>; --be-list-cols-sm: <?= $vatColsSm ?>; --be-list-cols-xs: <?= $vatColsSm ?>">
                <div class="be-list__head">
                    <span class="be-list__col">Konto ↔ Ziffer</span>
                    <span class="be-list__col be-list__col--num" data-priority="2">Buchhaltung</span>
                    <span class="be-list__col be-list__col--num" data-priority="2"><?= e($formLabel) ?></span>
                    <span class="be-list__col be-list__col--num">Nicht erklärt</span>
                </div>
                <?php foreach ($accounts as $a): ?>
                <div class="be-list__item">
                    <div class="be-list__row" data-vat-account="<?= e($a->accountNumber) ?>">
                        <span class="be-list__cell be-list__cell--wrap"><?= $link($a->accountNumber, $a->accountName) ?> ↔ <span class="be-list__cell--mono"><?= e(implode(' · ', $a->fields)) ?></span></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($a->movement)) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($a->expected)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $rest($a->difference()) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($totalField !== null && count($accounts) > 1): ?>
                <div class="be-list__item">
                    <div class="be-list__row be-list__row--total">
                        <span class="be-list__cell">Total ↔ <span class="be-list__cell--mono"><?= e($totalField) ?></span></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($sum($accounts, 'movement'))) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($sum($accounts, 'expected'))) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $rest($sum($accounts, 'difference')) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
