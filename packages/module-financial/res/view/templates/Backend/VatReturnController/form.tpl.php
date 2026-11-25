<?php
/**
 * One VAT return — tab «Formular» (screen decisions 4 and 5 of 2026-11-24):
 *
 *   - the card «Offene Punkte», only when there are findings: blocking first («sperrt» — «MWST
 *     buchen» will refuse, stage S2), then the warnings («Warnung» — to confirm there); each with the
 *     link its reference names (the account statement, the entries of a field, the tab
 *     «Abstimmung», the mandator's accounts, the bank account) — a finding of another module's open
 *     work names its place in its sentence;
 *   - the form in field order under the form's own sections (I Umsatz, II Steuerberechnung, III
 *     Vorsteuer, then the payable amount): ONLY the fields with an amount and the totals
 *     (`VatReturnField::$shown`); labels from the country pack. Umsatz and Steuer to the Rappen; the
 *     posted tax («gebucht») and the difference only at the total owed and at the payable amount;
 *     the exact figures are the PDF's. A negative field carries the word «negativ» (E9: shown and
 *     warned, not refused).
 *
 * NOT a standard list on purpose (listing.md): the form is a fixed sheet of at most ~25 rows —
 * a v2 table with its columns declared here; «gebucht» and «Differenz» drop on a narrow pane.
 *
 * Stage S2 (owner decisions 4–6 of 2026-11-25): an OPEN period lists the open points of the PLAN —
 * the posting preconditions first (`not-elapsed`, `not-next`, …), so the first row says why the
 * action cell is empty; «MWST buchen …» is the action cell's, never a button here. A POSTED period
 * and a lifted revision show the STORED figures (`$stored` set, `$fields` from `vat_return_figure`)
 * and no open points.
 *
 * Stage S3 (board S3-2): under the form the block «Korrekturen früherer Perioden — separat im
 * ePortal melden» (`correctionBlock`) — the correction entries dated in the period are NOT in the
 * form's fields; an open period computes the block, a posted one shows its stored rows.
 *
 * @var ?\Z77\Module\Financial\VatReturn\VatReturnPreview $preview  null on a lifted revision
 * @var ?\Z77\Module\Financial\Entities\VatReturn $stored
 * @var array{method: string, entry: string, reversal: string} $storedFacts  with $stored
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnField> $fields
 * @var list<array{finding: \Z77\Module\Financial\VatReturn\VatReturnFinding, link: ?array{url: string, label: string}}> $findingRows
 * @var \Z77\Shared\Money\Money $postedTax   the tax the coded lines posted (net, beside the payable amount)
 * @var \Z77\Shared\Money\Money $postedOwed  the posted output tax (beside the total owed)
 * @var string $periodLabel
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnCorrectionBlock> $corrections  the block, per corrected period (empty: none)
 * @var string $journalBase  the journal's URL root (a correction entry opens there)
 * @var callable $fmt
 */
use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Vat\CountryPack\FieldKind;

$ns       = 'Z77\\Module\\Financial';
$stored   = $stored ?? null;
$cols     = '3rem minmax(10rem, 1fr) 7.5rem 7.5rem 7rem 6rem';
$colsSm   = '3rem minmax(0, 1fr) 6.5rem 6.5rem';
$colsXs   = '2.6rem minmax(0, 1fr) 5.6rem 5.4rem';
$signed   = static fn(\Z77\Shared\Money\Money $m): string => ($m->isPositive() ? '+' : '') . $fmt($m);
$blocking = count(array_filter($findingRows, static fn(array $r): bool => $r['finding']->blocking));
?>
<div class="be-list" data-vat-return-view="form"<?= $stored !== null ? ' data-vat-return-stored="' . (int) $stored->getId() . '"' : '' ?>>
    <?php if ($findingRows !== []): ?>
    <div class="be-list__section" data-vat-findings>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Offene Punkte</h2>
            <span class="be-list__section-badge" title="<?= $blocking ?> sperrend, <?= count($findingRows) - $blocking ?> Warnungen"><?= count($findingRows) ?></span>
        </div>
        <div class="be-list__frame">
            <div class="be-list__table" style="--be-list-cols: 5.5rem minmax(0, 1fr) auto">
                <?php foreach ($findingRows as $row): ?>
                <?php $f = $row['finding']; ?>
                <div class="be-list__item">
                    <div class="be-list__row" data-vat-finding="<?= e($f->code) ?>" data-vat-finding-blocking="<?= $f->blocking ? '1' : '0' ?>">
                        <span class="be-list__cell"><span class="badge <?= $f->blocking ? 'badge--danger' : 'badge--warning' ?>"><?= $f->blocking ? 'sperrt' : 'Warnung' ?></span></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($f->message) ?></span>
                        <span class="be-list__cell"><?php if ($row['link'] !== null): ?><a href="<?= e($row['link']['url']) ?>"><?= e($row['link']['label']) ?></a><?php endif; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="be-list__section" data-vat-form>
        <?= $this->partial('Backend/VatReturnController/head', ['preview' => $preview, 'stored' => $stored, 'storedFacts' => $storedFacts ?? [], 'periodLabel' => $periodLabel], $ns) ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: <?= $cols ?>; --be-list-cols-sm: <?= $colsSm ?>; --be-list-cols-xs: <?= $colsXs ?>">
                <div class="be-list__head">
                    <span class="be-list__col">Ziffer</span>
                    <span class="be-list__col">Bezeichnung</span>
                    <span class="be-list__col be-list__col--num">Umsatz</span>
                    <span class="be-list__col be-list__col--num">Steuer</span>
                    <span class="be-list__col be-list__col--num" data-priority="3" title="Die Steuer, wie die Belege sie gebucht haben">gebucht</span>
                    <span class="be-list__col be-list__col--num" data-priority="3" title="Gebucht minus abgerechnet">Differenz</span>
                </div>
                <?php $section = null; ?>
                <?php foreach ($fields as $field): ?>
                <?php if (!$field->shown) { continue; } ?>
                <?php
                $kind = $field->kind;
                $here = VatReturnLayout::section($kind);
                $turnover = VatReturnLayout::isTurnover($kind) ? $field->rounded : $field->base;
                $tax      = VatReturnLayout::isTurnover($kind) ? null : $field->rounded;
                $posted   = match ($kind) {
                    FieldKind::TaxTotal => $postedOwed,
                    FieldKind::Payable  => $postedTax,
                    FieldKind::Credit   => $postedTax->negate(),
                    default             => null,
                };
                $negative = $field->rounded->isNegative() || ($field->base?->isNegative() ?? false);
                $class    = $here === 'payable' ? ' be-list__row--total' : (VatReturnLayout::isTotal($kind) ? ' be-list__row--l1' : '');
                ?>
                <?php if ($here !== $section && VatReturnLayout::SECTIONS[$here] !== ''): ?>
                <div class="be-list__item">
                    <div class="be-list__row be-list__row--l2"><span class="be-list__cell be-list__cell--span"><?= e(VatReturnLayout::SECTIONS[$here]) ?></span></div>
                </div>
                <?php endif; ?>
                <?php $section = $here; ?>
                <div class="be-list__item">
                    <div class="be-list__row<?= $class ?>" id="ziffer-<?= e($field->field) ?>" data-vat-field="<?= e($field->field) ?>">
                        <span class="be-list__cell be-list__cell--mono"><?= e($field->field) ?></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($field->label) ?><?= $negative ? ' <span class="badge badge--warning">negativ</span>' : '' ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $turnover === null ? '' : e($fmt($turnover)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $tax === null ? '' : e($fmt($tax)) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="3"<?= $posted !== null ? ' data-vat-posted' : '' ?>><?= $posted === null ? '' : e($fmt($posted)) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="3"<?= $posted !== null ? ' data-vat-difference' : '' ?>><?= $posted === null ? '' : e($signed($posted->subtract($field->rounded))) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?= $this->partial('Backend/VatReturnController/correctionBlock', ['blocks' => $corrections ?? [], 'journalBase' => $journalBase ?? VatReturnLayout::JOURNAL_BASE, 'fmt' => $fmt], $ns) ?>
</div>
