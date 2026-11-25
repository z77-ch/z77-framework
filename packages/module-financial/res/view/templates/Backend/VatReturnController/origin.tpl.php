<?php
/**
 * One VAT return — tab «Herkunft» (screen decision 8 of 2026-11-24, variant A — the tree): form
 * field › origin (invoices, credit notes, receipts, creditor invoices, bank, manual entries — the
 * source type of every entry) › account, collapsed when the page opens. A level opens with the
 * chevron in front (`.be-list__table--tree`, the list's checkbox disclosure one step further — no
 * JavaScript); an account row opens the entries of that node (`entries`, decision 9), whose total
 * is the figure shown here.
 *
 * Only fields filled by coded journal lines appear (the totals are the form's). Columns: Buchungen
 * (coded lines), Umsatz (the node's share of the field's figure: the base, for 235 the reduction,
 * empty for the input fields), Steuer gebucht (the posted tax of the lines, empty for 200 and the
 * deductions). NOT a standard list on purpose — a fixed tree of the return, no search, no paging.
 *
 * A POSTED return: computed from the journal all the same; one hint line on top when it no longer
 * matches what was posted (`changedSince`).
 *
 * @var \Z77\Module\Financial\VatReturn\VatReturnPreview $preview
 * @var callable $entriesUrl  (field, ?origin, ?accountId) → URL of the entries list
 * @var ?\DateTimeImmutable $changedSince  a posted return whose view differs: its posting time
 * @var string $voucherUrl                  a posted return: its archived voucher
 * @var string $periodLabel
 * @var callable $fmt
 */
use Z77\Module\Vat\CountryPack\FieldKind;

$ns     = 'Z77\\Module\\Financial';
$cols   = 'minmax(10rem, 1fr) 6rem 8rem 8rem';
$colsSm = 'minmax(0, 1fr) 6.5rem 6.5rem';
$kinds  = [];
foreach ($preview->fields as $f) {
    $kinds[$f->field] = $f->kind;
}
/** Umsatz: the node's amount on a turnover or rate field; Steuer: the posted tax on a rate or input field. */
$amounts = static function (FieldKind $kind, \Z77\Module\Financial\VatReturn\VatReturnBreakdown $node) use ($fmt): array {
    $input = in_array($kind, [FieldKind::Input, FieldKind::InputCorrection], true);
    $tax   = in_array($kind, [FieldKind::Rate, FieldKind::Acquisition, FieldKind::Input, FieldKind::InputCorrection], true);

    return [$input ? '' : $fmt($node->amount), $tax ? $fmt($node->tax) : ''];
};
?>
<div class="be-list" data-vat-return-view="origin">
    <?= $this->partial('Backend/VatReturnController/changedSince', ['changedSince' => $changedSince ?? null, 'voucherUrl' => $voucherUrl ?? ''], $ns) ?>
    <div class="be-list__section" data-vat-origin>
        <?= $this->partial('Backend/VatReturnController/head', ['preview' => $preview, 'periodLabel' => $periodLabel, 'title' => 'Herkunft ' . $periodLabel], $ns) ?>
        <?php if ($preview->breakdown === []): ?>
        <p class="be-list__empty">Keine Buchung mit MWST-Code in dieser Periode.</p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--disclose be-list__table--tree be-list__table--drop" style="--be-list-cols: <?= $cols ?>; --be-list-cols-sm: <?= $colsSm ?>; --be-list-cols-xs: <?= $colsSm ?>">
                <div class="be-list__head">
                    <span class="be-list__col" aria-label="Aufklappen"></span>
                    <span class="be-list__col">Ziffer · Herkunft · Konto</span>
                    <span class="be-list__col be-list__col--num" data-priority="2">Buchungen</span>
                    <span class="be-list__col be-list__col--num">Umsatz</span>
                    <span class="be-list__col be-list__col--num">Steuer gebucht</span>
                </div>
                <?php foreach ($preview->breakdown as $fieldNode): ?>
                <?php $kind = $kinds[$fieldNode->key]; [$turnover, $tax] = $amounts($kind, $fieldNode); $fid = 'vat-origin-' . $fieldNode->key; ?>
                <div class="be-list__item" data-vat-node="<?= e($fieldNode->key) ?>">
                    <input class="be-list__disclosure-input" type="checkbox" id="<?= e($fid) ?>">
                    <div class="be-list__row be-list__row--l1">
                        <label class="be-list__cell be-list__disclosure" for="<?= e($fid) ?>" title="Aufklappen"><svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-chevron-right"/></svg></label>
                        <label class="be-list__cell" for="<?= e($fid) ?>"><span class="be-list__cell--mono"><?= e($fieldNode->key) ?></span> <?= e($fieldNode->label) ?></label>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= $fieldNode->lines ?></span>
                        <span class="be-list__cell be-list__cell--num" data-vat-amount><?= e($turnover) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($tax) ?></span>
                    </div>
                    <?php foreach ($fieldNode->children as $originNode): ?>
                    <?php [$turnover, $tax] = $amounts($kind, $originNode); $oid = $fid . '-' . $originNode->key; ?>
                    <div class="be-list__item" data-vat-node="<?= e($fieldNode->key . '/' . $originNode->key) ?>">
                        <input class="be-list__disclosure-input" type="checkbox" id="<?= e($oid) ?>">
                        <div class="be-list__row">
                            <label class="be-list__cell be-list__disclosure" for="<?= e($oid) ?>" title="Aufklappen"><svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-chevron-right"/></svg></label>
                            <label class="be-list__cell be-list__cell--indent" for="<?= e($oid) ?>"><?= e($originNode->label) ?></label>
                            <span class="be-list__cell be-list__cell--num" data-priority="2"><?= $originNode->lines ?></span>
                            <span class="be-list__cell be-list__cell--num"><?= e($turnover) ?></span>
                            <span class="be-list__cell be-list__cell--num"><?= e($tax) ?></span>
                        </div>
                        <?php foreach ($originNode->children as $accountNode): ?>
                        <?php [$turnover, $tax] = $amounts($kind, $accountNode); $url = $entriesUrl($fieldNode->key, $originNode->key, $accountNode->accountId); ?>
                        <div class="be-list__item" data-vat-node="<?= e($fieldNode->key . '/' . $originNode->key . '/' . $accountNode->key) ?>">
                            <div class="be-list__row">
                                <a class="be-list__cell be-list__state" href="<?= e($url) ?>" title="Buchungen anzeigen" aria-label="Buchungen <?= e($accountNode->key) ?> zeigen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-search"/></svg></a>
                                <a class="be-list__cell be-list__cell--indent-2" href="<?= e($url) ?>"><span class="be-list__cell--mono"><?= e($accountNode->key) ?></span> <?= e($accountNode->label) ?></a>
                                <span class="be-list__cell be-list__cell--num" data-priority="2"><?= $accountNode->lines ?></span>
                                <span class="be-list__cell be-list__cell--num"><?= e($turnover) ?></span>
                                <span class="be-list__cell be-list__cell--num"><?= e($tax) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
