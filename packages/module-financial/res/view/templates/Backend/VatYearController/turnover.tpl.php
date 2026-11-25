<?php
/**
 * The yearly overview — tab «Umsatz» (Art. 128 para. 2 MWSTV; board S7-2 part 4): the revenue
 * accounts of the calendar year (movements, the year opening and the settlement entries left out —
 * the rule of the return's «Abstimmung»), the corrections for the year reported later beside it
 * («Neu deklariert»), and the reconciliation «Ertrag → Deklaration» with the letters a–j: an item
 * z77 cannot know stands «von Hand prüfen» without amount; an item for information only is muted;
 * the unexplained remainder is red when it is not 0.00 — it blocks nothing (small rule 3). The
 * account numbers link the account statement.
 *
 * @var \Z77\Module\Financial\VatReturn\VatYearOverview $overview
 * @var callable $accountUrl  number → the account statement over the year
 * @var callable $fmt
 * @var string $currency
 */
use Z77\Module\Financial\Pdf\VatYearPdf;
use Z77\Module\Financial\Ui\VatYearLayout;

$year      = $overview->taxYear->year;
$t         = $overview->turnover;
$remainder = $t->remainder();
$later     = array_values(array_filter($overview->corrections, static fn($c): bool => $c->laterYear));
$cols      = '--be-list-cols: 2rem minmax(10rem, 1fr) 8rem; --be-list-cols-xs: 1.6rem minmax(0, 1fr) 6.5rem';
?>
<div class="be-list__section" data-vat-year-revenue>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Ertrag <?= (int) $year ?> gemäss Buchhaltung</h2>
    </div>
    <p class="be-list__section-hint">Ertragskonten, <?= e(VatYearLayout::yearSpan($overview)) ?>, ohne Eröffnung und MWST-Abrechnungsbuchungen</p>
    <div class="be-list__frame">
        <div class="be-list__table" style="--be-list-cols: 5rem minmax(10rem, 1fr) 8rem; --be-list-cols-xs: 4rem minmax(0, 1fr) 6.5rem">
            <div class="be-list__head">
                <span class="be-list__col">Konto</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Bewegung</span>
            </div>
            <?php foreach ($t->revenueAccounts as $line): ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-revenue-account="<?= e($line->accountNumber) ?>">
                    <span class="be-list__cell be-list__cell--mono"><a href="<?= e($accountUrl($line->accountNumber)) ?>"><?= e($line->accountNumber) ?></a></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($line->accountName) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->amount)) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="be-list__item">
                <div class="be-list__row be-list__row--total">
                    <span class="be-list__cell"></span>
                    <span class="be-list__cell">Total Ertrag <?= (int) $year ?></span>
                    <span class="be-list__cell be-list__cell--num" data-vat-year-revenue-total><?= e($fmt($t->revenueTotal)) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="be-list__section" data-vat-year-declared>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Korrekturen für <?= (int) $year ?>, später gemeldet</h2>
    </div>
    <div class="be-list__frame">
        <div class="be-list__table" style="--be-list-cols: minmax(10rem, 1fr) 8rem; --be-list-cols-xs: minmax(0, 1fr) 6.5rem">
            <?php foreach ($later as $c): ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-later="<?= e($c->block->period->from->format('Y-m-d')) ?>">
                    <span class="be-list__cell be-list__cell--wrap"><?= e($c->block->period->label() . ' · ' . $c->block->entryRefs() . ' · gebucht im Geschäftsjahr ' . $c->carryingReturn->getPeriodFrom()->format('Y')) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e(VatYearLayout::signed(VatYearLayout::blockTurnover($c, $currency))) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell">Deklariert, ordentliche Abrechnungen <?= (int) $year ?></span>
                    <span class="be-list__cell be-list__cell--num" data-vat-year-declared-total><?= e($fmt($t->declared)) ?></span>
                </div>
            </div>
            <div class="be-list__item">
                <div class="be-list__row be-list__row--total">
                    <span class="be-list__cell">Neu deklariert <?= (int) $year ?></span>
                    <span class="be-list__cell be-list__cell--num" data-vat-year-declared-new><?= e($fmt($t->correctedDeclared)) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="be-list__section" data-vat-year-bridge>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Überleitung Ertrag → Deklaration</h2>
    </div>
    <p class="be-list__section-hint">Art. 128 Abs. 2 MWSTV, Buchstaben a–j</p>
    <div class="be-list__frame">
        <div class="be-list__table" style="<?= $cols ?>">
            <div class="be-list__head">
                <span class="be-list__col"></span>
                <span class="be-list__col">Position</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
            </div>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--mono">a</span>
                    <span class="be-list__cell">Total Ertrag <?= (int) $year ?> gemäss Buchhaltung</span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($t->revenueTotal)) ?></span>
                </div>
            </div>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"></span>
                    <span class="be-list__cell">Deklariert, ordentliche Abrechnungen <?= (int) $year ?> (Ziffer 200 − 235)</span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($t->declared)) ?></span>
                </div>
            </div>
            <?php foreach ($t->items as $item): ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-item="<?= e($item->kind) ?>">
                    <span class="be-list__cell be-list__cell--mono"><?= e((string) $item->letter) ?></span>
                    <span class="be-list__cell be-list__cell--wrap<?= $item->counted ? '' : ' be-list__cell--muted' ?>"><?php if ($item->accountNumber !== ''): ?><?= e($item->label) ?> — <a href="<?= e($accountUrl($item->accountNumber)) ?>"><?= e($item->accountNumber) ?></a> <?= e($item->accountName) ?><?php else: ?><?= e(VatYearPdf::itemLabel($item)) ?><?php endif; ?></span>
                    <span class="be-list__cell be-list__cell--num<?= $item->counted ? '' : ' be-list__cell--muted' ?>"><?= $item->amount === null ? 'von Hand prüfen' : e($fmt($item->amount)) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="be-list__item">
                <div class="be-list__row be-list__row--total" data-vat-year-remainder="<?= $remainder->isZero() ? '0' : '1' ?>">
                    <span class="be-list__cell"></span>
                    <span class="be-list__cell">Unerklärter Rest</span>
                    <span class="be-list__cell be-list__cell--num"><?php if ($remainder->isZero()): ?><?= e($fmt($remainder)) ?><?php else: ?><span class="badge badge--danger"><?= e($fmt($remainder)) ?></span><?php endif; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
