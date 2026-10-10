<?php
/**
 * The parameters of a report page (financial.md, P2 part 3): a GET form —
 * the fiscal year travels as a hidden field (the year is switched in hc2,
 * whose links carry no dates, so a date of another year never arrives
 * here), from and to (the balance sheet only «Stichtag»), the account on the
 * account statement — and the «Zeitraum» select (whole year, the months)
 * between the date and «Anzeigen». Then what was not usable in the request. Screen only
 * (`.be-noprint`): the printed report carries its range in its title.
 *
 * Styling: the shared form classes (`.be-form__grid`, `.be-form__field`,
 * `.be-input`, `.be-btn`) — no CSS and no JavaScript of its own.
 *
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var string $tab       the report's URL action
 * @var callable $link    (report, params) → URL with the current range
 * @var list<string> $months
 * @var string $reportBase
 * @var list<string> $notices
 * @var array<string,string> $keep   parameters the shortcuts keep (the account)
 * @var bool $atDay       the balance sheet: «Stichtag» only
 * @var list<\Z77\Module\Financial\Entities\Account>|null $accounts  the account statement's select
 * @var string $accountNumber
 */
$keep     = $keep ?? [];
$atDay    = $atDay ?? false;
$accounts = $accounts ?? null;
$year     = $range->year;
$min      = $year->getStartDate()->format('Y-m-d');
$max      = $year->getEndDate()->format('Y-m-d');
?>
<div class="be-list__section be-noprint">
    <form method="get" action="<?= e($reportBase . '/' . $tab) ?>" class="be-form__grid" style="grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); align-items: end">
        <input type="hidden" name="year" value="<?= e($year->getCode()) ?>">
        <?php if ($accounts !== null): ?>
        <div class="be-form__field">
            <label for="report-account">Konto</label>
            <select id="report-account" name="account" required>
                <option value="">— Konto wählen —</option>
                <?php foreach ($accounts as $account): ?>
                <option value="<?= e($account->getNumber()) ?>"<?= $account->getNumber() === ($accountNumber ?? '') ? ' selected' : '' ?>><?= e($account->getNumber() . ' ' . $account->getName()) ?><?= $account->isActive() ? '' : ' (inaktiv)' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if (!$atDay): ?>
        <div class="be-form__field">
            <label for="report-from">Von</label>
            <input id="report-from" type="date" name="from" value="<?= e($range->fromDay()) ?>" min="<?= e($min) ?>" max="<?= e($max) ?>">
        </div>
        <?php endif; ?>
        <div class="be-form__field">
            <label for="report-to"><?= $atDay ? 'Stichtag' : 'Bis' ?></label>
            <input id="report-to" type="date" name="to" value="<?= e($range->toDay()) ?>" min="<?= e($min) ?>" max="<?= e($max) ?>">
        </div>
        <?php
        // «Zeitraum» (owner 2026-10-10): «Ganzes Jahr» and the months in ONE select between
        // the date and «Anzeigen» — it replaced a row of 13 buttons. The option that matches
        // the shown range is selected (the balance sheet matches on the Stichtag only); an
        // edited date wins over it on the server (`shown_from` / `shown_to`, reportRange()).
        $toDay   = $range->toDay();
        $fromDay = $range->fromDay();
        $matches = static fn(string $f, string $t): bool => $t === $toDay && ($atDay || $f === $fromDay);
        $options = [['year', 'Ganzes Jahr', $matches($min, $max)]];
        foreach ($year->getPeriods() as $p) {
            $f = $p->getStartDate()->format('Y-m-d');
            $t = $p->getEndDate()->format('Y-m-d');
            $options[] = [$p->getStartDate()->format('Y-m'), $months[(int) $p->getStartDate()->format('n') - 1] . ' ' . $p->getStartDate()->format('Y'), $matches($f, $t)];
        }
        $anySelected = in_array(true, array_column($options, 2), true);
        ?>
        <div class="be-form__field">
            <label for="report-period">Zeitraum</label>
            <input type="hidden" name="shown_from" value="<?= e($atDay ? '' : $fromDay) ?>">
            <input type="hidden" name="shown_to" value="<?= e($toDay) ?>">
            <select id="report-period" name="period">
                <?php if (!$anySelected): ?>
                <option value="" selected>Eigene Daten</option>
                <?php endif; ?>
                <?php foreach ($options as [$value, $label, $selected]): ?>
                <option value="<?= e($value) ?>"<?= $selected ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php // «Anzeige» (owner 2026-10-10): one field, two ways to show the SAME form values —
              // on the screen («Bildschirm», this page) or as a real PDF in a new tab (FIN-PDF-001).
              // The PDF button submits the form itself (`formaction` + `formtarget`), so an edited
              // date or a picked period reaches the PDF without pressing «Anzeigen» first. ?>
        <?php if (in_array($tab, $compareTabs ?? [], true)): ?>
        <?php // «Vorjahre» (owner 2026-10-10): the two years before beside the shown one; on
              // by default. A GET form sends nothing for an unticked box, so a hidden `0` goes
              // first and a ticked box overrides it with `1` (the last value wins) — an absent
              // parameter stays «first load = on». ?>
        <div class="be-form__field">
            <span class="be-form__label">Vergleich</span>
            <input type="hidden" name="compare" value="0">
            <label class="be-choice">
                <input type="checkbox" class="be-choice__input" name="compare" value="1"<?= ($compare ?? true) ? ' checked' : '' ?>>
                <span class="be-choice__label">Vorjahre</span>
            </label>
        </div>
        <?php endif; ?>
        <div class="be-form__field">
            <span class="be-form__label">Anzeige</span>
            <div class="be-form__buttons">
                <button type="submit" class="be-btn be-btn--primary" title="Am Bildschirm anzeigen">
                    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-monitor"/></svg> <span class="be-btn__label">Bildschirm</span>
                </button>
                <?php if (in_array($tab, $pdfTabs ?? [], true)): ?>
                <button type="submit" class="be-btn be-btn--ghost" name="report" value="<?= e($tab) ?>"
                        formaction="<?= e($reportBase . '/pdf') ?>" formtarget="_blank" title="Als PDF in neuem Tab öffnen">
                    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-download"/></svg> <span class="be-btn__label">PDF</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <?php if (($notices ?? []) !== []): ?>
    <div class="be-modal__alert be-modal__alert--error">
        <?php foreach ($notices as $notice): ?>
        <div><?= e($notice) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
