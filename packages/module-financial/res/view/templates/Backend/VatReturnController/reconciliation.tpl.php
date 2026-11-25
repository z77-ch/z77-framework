<?php
/**
 * One VAT return — tab «Abstimmung» (ADR-041 addendum point 6, review C8): the accounts as the
 * CONTROL of the return, movements of the period, never balances. Three cards:
 *
 *   - «Umsatz»: the revenue accounts (a disclosure, one row per account), «Formular: Ziffer 200
 *     abzüglich Ziffer 235», the «Unterschied», «davon erklärt» with each named item (lines without
 *     a tax code per revenue account, output-coded lines on another account type, one line per
 *     correction entry — its entry number as text, it has no account), «Nicht erklärt»;
 *   - «Vorsteuer» and «Geschuldete MWST»: each VAT account ↔ its form fields — Buchhaltung (the
 *     account's movement), Formular (the tax the coded lines posted), Nicht erklärt (the gap — it
 *     BLOCKS «MWST buchen», ADR-042 addendum point 1).
 *
 * ONLY «Nicht erklärt» takes the alert colour, and only when it is not 0.00 (the board's rule —
 * the difference itself stays quiet): an existing pill, `badge badge--danger`. Account numbers link
 * to the account statement of the period. No JavaScript (the revenue rows open with the list's
 * checkbox disclosure). NOT a standard list on purpose — fixed sheets.
 *
 * A POSTED return: computed from the journal all the same; one hint line on top when it no longer
 * matches what was posted (`changedSince`).
 *
 * Under each VAT account: «Buchungen ohne passenden Code (n)» — every entry of the period whose
 * movement on the account differs from the tax of its own coded lines (owner S4-5,
 * `VatReturn\VatAccountViolations`), one row each: the entry number opens the entry in the journal
 * as a window, date · text, «getippte Zeile» when it has a line without a tax code on the account;
 * the three amounts in the account's columns (its movement, the tax of its codes, the difference —
 * quiet, the alert stays the account's). The account row carries the anchor `vat-account-{number}`
 * the open point of a gap links to.
 *
 * @var ?\DateTimeImmutable $changedSince  a posted return whose view differs: its posting time
 * @var string $voucherUrl                  a posted return: its archived voucher
 * @var \Z77\Module\Financial\VatReturn\VatReturnReconciliation $reconciliation
 * @var \Z77\Module\Financial\VatReturn\VatReturnPreview $preview
 * @var callable $accountUrl  account number → its account statement over the period
 * @var array<string, list<array{entryId: int, ref: string, date: \DateTimeImmutable, text: string, expected: \Z77\Shared\Money\Money, movement: \Z77\Shared\Money\Money, difference: \Z77\Shared\Money\Money, typed: bool}>> $violations account number → its entries that do not fit
 * @var string $journalBase   the journal's URL root (the entry window)
 * @var string $periodLabel
 * @var callable $fmt
 */
use Z77\Module\Financial\VatReturn\ReconciliationLine;
use Z77\Module\Financial\VatReturn\VatAccountReconciliation;

$r      = $reconciliation;
$rest   = static fn(\Z77\Shared\Money\Money $m): string => $m->isZero()
    ? '<span data-vat-rest="0">' . e($fmt($m)) . '</span>'
    : '<span class="badge badge--danger" data-vat-rest="1">' . e($fmt($m)) . '</span>';
$link   = static fn(string $number, string $name): string => $number === ''
    ? '<span class="be-list__cell--muted">kein Konto hinterlegt</span>'
    : '<a class="be-list__cell--mono" href="' . e($accountUrl($number)) . '">' . e($number) . '</a> ' . e($name);
// A correction entry's line carries its ENTRY reference («2027/214»), not an account — text, no account-statement link.
$entry  = static fn(string $ref, string $name): string => '<span class="be-list__cell--mono">' . e($ref) . '</span> ' . e($name);
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
    <?= $this->partial('Backend/VatReturnController/changedSince', ['changedSince' => $changedSince ?? null, 'voucherUrl' => $voucherUrl ?? ''], 'Z77\\Module\\Financial') ?>
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
                        <span class="be-list__cell be-list__cell--wrap"><?= e($line->label()) ?> · <?= $line->kind === ReconciliationLine::CORRECTION ? $entry($line->accountNumber, $line->accountName) : $link($line->accountNumber, $line->accountName) ?></span>
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
                <div class="be-list__item"<?= $a->accountNumber !== '' ? ' id="vat-account-' . e($a->accountNumber) . '"' : '' ?>>
                    <div class="be-list__row" data-vat-account="<?= e($a->accountNumber) ?>">
                        <span class="be-list__cell be-list__cell--wrap"><?= $link($a->accountNumber, $a->accountName) ?> ↔ <span class="be-list__cell--mono"><?= e(implode(' · ', $a->fields)) ?></span></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($a->movement)) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($a->expected)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $rest($a->difference()) ?></span>
                    </div>
                </div>
                <?php $misfits = $a->accountNumber === '' ? [] : ($violations[$a->accountNumber] ?? []); ?>
                <?php if ($misfits !== []): ?>
                <div class="be-list__item" data-vat-violations="<?= e($a->accountNumber) ?>">
                    <div class="be-list__row be-list__row--l2">
                        <span class="be-list__cell be-list__cell--indent">Buchungen ohne passenden Code (<?= count($misfits) ?>)</span>
                        <span class="be-list__cell" data-priority="2"></span>
                        <span class="be-list__cell" data-priority="2"></span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
                <?php foreach ($misfits as $v): ?>
                <?php $detail = $journalBase . '/detail?id=' . $v['entryId']; ?>
                <div class="be-list__item">
                    <div class="be-list__row" data-vat-violation="<?= e((string) $v['entryId']) ?>">
                        <span class="be-list__cell be-list__cell--wrap be-list__cell--indent"><a class="be-list__cell--mono" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>" title="Buchung <?= e($v['ref']) ?> öffnen"><?= e($v['ref']) ?></a> <?= e($v['date']->format('d.m.Y')) ?> · <?= e($v['text']) ?><?= $v['typed'] ? ' · <span class="be-list__cell--muted">getippte Zeile ' . e($a->accountNumber) . '</span>' : '' ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($v['movement'])) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($v['expected'])) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($v['difference'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
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
