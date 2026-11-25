<?php
/**
 * The yearly overview — tab «Vorsteuer» (Art. 128 para. 3 MWSTV and the owed side; board S7-2
 * part 5; owner B-1):
 *
 *   - «MWST-Konten {Jahr}» — per VAT account of the mandator its movement over the year, the fields
 *     it is declared in, what the year's returns declared, the rounding (the coded tax − declared)
 *     and the remainder (movement − the tax of the coded lines; red when ≠ 0.00, blocks nothing).
 *     Below them the transit accounts per 31.12.: 1179 «Vorsteuer, noch nicht abgerechnet» (stands
 *     at 0.00 — red otherwise) and 2209 «MWST-Korrektur, noch nicht abgerechnet» with what of it is
 *     still OPEN today (B-1 — the reader the year-close warning uses). An account row carries the
 *     anchor `vat-account-{number}` (the open point «Rest» links there);
 *   - «Korrekturen der Vorsteuer (Ziffern 410, 415, 420)» — the fields of those categories, or none;
 *   - «Korrekturen für {Jahr}, gebucht in einem späteren Jahr» — the payable difference of each block
 *     carried by a return of a later year (its tax moved the VAT accounts in that year);
 *   - «Information» — the rounding of the year's returns, the settlement account per 31.12., the sum
 *     of the payable amounts.
 *
 * @var \Z77\Module\Financial\VatReturn\VatYearOverview $overview
 * @var callable $accountUrl
 * @var callable $fmt
 * @var ?string $roundingAccount
 * @var list<string> $inputFields
 * @var string $currency
 */
use Z77\Module\Financial\Ui\VatYearLayout;
use Z77\Module\Financial\VatReturn\VatYearBalance;

$year  = $overview->taxYear->year;
$later = array_values(array_filter($overview->corrections, static fn($c): bool => $c->laterYear));
$red   = static fn(\Z77\Shared\Money\Money $m): string => $m->isZero() ? e($fmt($m)) : '<span class="badge badge--danger">' . e($fmt($m)) . '</span>';
$link  = static fn(string $number): string => '<a href="' . e($accountUrl($number)) . '">' . e($number) . '</a>';
$inputRows = [];
foreach ($inputFields as $number) {
    $f = $overview->field($number);
    if ($f !== null && (!$f->declared->isZero() || $f->hasCorrection())) {
        $inputRows[] = $f;
    }
}
?>
<div class="be-list__section" data-vat-year-accounts>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">MWST-Konten <?= (int) $year ?></h2>
    </div>
    <p class="be-list__section-hint">Bewegung <?= e(VatYearLayout::yearSpan($overview)) ?> ohne Eröffnung und MWST-Abrechnungsbuchungen</p>
    <div class="be-list__frame">
        <div class="be-list__table be-list__table--drop" style="--be-list-cols: 4.5rem minmax(10rem, 1fr) 7rem 4.5rem 7rem 6.5rem 6.5rem; --be-list-cols-sm: 4rem minmax(0, 1fr) 6.5rem 6rem">
            <div class="be-list__head">
                <span class="be-list__col">Konto</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Bewegung</span>
                <span class="be-list__col" data-priority="3">Ziffer</span>
                <span class="be-list__col be-list__col--num" data-priority="3">deklariert</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Rundung</span>
                <span class="be-list__col be-list__col--num">Rest</span>
            </div>
            <?php foreach ($overview->accounts as $a): ?>
            <div class="be-list__item">
                <div class="be-list__row" id="vat-account-<?= e($a->accountNumber) ?>" data-vat-year-account="<?= e($a->accountNumber) ?>">
                    <span class="be-list__cell be-list__cell--mono"><?= $link($a->accountNumber) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($a->accountName) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($a->movement)) ?></span>
                    <span class="be-list__cell" data-priority="3"><?= e(implode(', ', $a->fields)) ?></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= e($fmt($a->declared)) ?></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= e($fmt($a->difference())) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $red($a->remainder()) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php foreach ([$overview->inputPending, $overview->correctionPending] as $balance): ?>
            <?php if (!$balance instanceof VatYearBalance) { continue; } ?>
            <div class="be-list__item">
                <div class="be-list__row" id="vat-account-<?= e($balance->accountNumber) ?>" data-vat-year-transit="<?= e($balance->accountNumber) ?>">
                    <span class="be-list__cell be-list__cell--mono"><?= $link($balance->accountNumber) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($balance->accountName) ?> — Saldo 31.12.<?= (int) $year ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($balance->balance)) ?></span>
                    <span class="be-list__cell" data-priority="3"></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"></span>
                    <span class="be-list__cell be-list__cell--num"><?php if ($balance->open === null): ?><?= $red($balance->balance) ?><?php else: ?><span data-vat-year-open>offen heute <?= e($fmt($balance->open)) ?></span><?php endif; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($overview->accounts === []): ?>
            <p class="be-list__empty">Beim Mandanten ist kein MWST-Konto hinterlegt.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="be-list__section" data-vat-year-input-corrections>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Korrekturen der Vorsteuer<?= $inputFields === [] ? '' : ' (Ziffern ' . e(implode(', ', $inputFields)) . ')' ?></h2>
    </div>
    <?php if ($inputRows === []): ?>
    <p class="be-list__empty">keine</p>
    <?php else: ?>
    <div class="be-list__frame">
        <div class="be-list__table" style="--be-list-cols: 3rem minmax(10rem, 1fr) 7rem 7rem 7rem; --be-list-cols-xs: 2.6rem minmax(0, 1fr) 6rem">
            <div class="be-list__head">
                <span class="be-list__col">Ziffer</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num" data-priority="3">deklariert</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Korrekturen</span>
                <span class="be-list__col be-list__col--num">neu</span>
            </div>
            <?php foreach ($inputRows as $f): ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-input-field="<?= e($f->field) ?>">
                    <span class="be-list__cell be-list__cell--mono"><?= e($f->field) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($f->label) ?></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= e($fmt($f->declared->rounded())) ?></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= $f->corrections->isZero() ? '—' : e(VatYearLayout::signed($f->corrections->rounded())) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($f->newValue()->rounded())) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($later !== []): ?>
<div class="be-list__section" data-vat-year-later-booked>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Korrekturen für <?= (int) $year ?>, gebucht in einem späteren Jahr</h2>
    </div>
    <div class="be-list__frame">
        <div class="be-list__table" style="--be-list-cols: minmax(10rem, 1fr) 8rem; --be-list-cols-xs: minmax(0, 1fr) 6.5rem">
            <div class="be-list__head">
                <span class="be-list__col">Korrekturbuchung · abgerechnet mit</span>
                <span class="be-list__col be-list__col--num">Differenz Zahllast</span>
            </div>
            <?php foreach ($later as $c): ?>
            <?php $entry = $c->carryingReturn->getSettlementEntry(); ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--wrap"><?= e($c->block->entryRefs() . ' · Abrechnung ' . $c->carryingReturn->period()->label() . ($entry === null ? '' : ', Buchung ' . \Z77\Module\Financial\Ui\VatReturnLayout::entryRef($entry))) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e(VatYearLayout::signed(VatYearLayout::blockPayable($c, $currency))) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="be-list__section" data-vat-year-information>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Information</h2>
    </div>
    <div class="be-list__frame">
        <div class="be-list__table" style="--be-list-cols: 4.5rem minmax(10rem, 1fr) 8rem; --be-list-cols-xs: 4rem minmax(0, 1fr) 6.5rem">
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-rounding>
                    <span class="be-list__cell be-list__cell--mono"><?= $roundingAccount === null ? '' : $link($roundingAccount) ?></span>
                    <span class="be-list__cell be-list__cell--wrap">Rundungsdifferenzen MWST aus den Abrechnungen <?= (int) $year ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($overview->rounding)) ?></span>
                </div>
            </div>
            <?php if ($overview->settlement !== null): ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-settlement>
                    <span class="be-list__cell be-list__cell--mono"><?= $link($overview->settlement->accountNumber) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($overview->settlement->accountName) ?>, Saldo am 31.12.<?= (int) $year ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($overview->settlement->balance)) ?></span>
                </div>
            </div>
            <?php endif; ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"></span>
                    <span class="be-list__cell be-list__cell--wrap">Zahllast der Abrechnungen <?= (int) $year ?>, Summe</span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($overview->payables)) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
