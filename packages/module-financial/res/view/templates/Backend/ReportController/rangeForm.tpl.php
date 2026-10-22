<?php
/**
 * The parameters of a report page (financial.md, P2 part 3): a GET form —
 * the fiscal year travels as a hidden field (the year is switched at the top
 * of the rail, `railSelect`, whose links carry no dates, so a date of another
 * year never arrives here), from and to (the balance sheet only «Stichtag»),
 * the account on the account statement — and the range select (whole year,
 * halves, quarters, months; at a day their ends) after the dates. Then what was not
 * usable in the request. Paper is the PDF (the «PDF» button submits this
 * form, FIN-PDF-001) — the browser print of the reports is gone, so the form
 * carries no print-only class any more.
 *
 * Styling: the shared form classes (`.be-form__grid`, `.be-form__field`,
 * `.be-input`, `.be-btn`) — no CSS and no script of its own (the select's
 * behaviour is core.js `select[data-range-presets]`, progressive).
 *
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var string $tab       the report's URL action
 * @var callable $link    (report, params) → URL with the current range
 * @var list<string> $months
 * @var string $reportBase
 * @var list<string> $notices
 * @var array<string,string> $keep   parameters the shortcuts keep (the account)
 * @var bool $atDay       the balance sheet: «Stichtag» only, the choices are section ends
 * @var list<\Z77\Module\Financial\Reports\ReportPreset> $presets  the select's choices
 * @var ?string $presetKey the choice that agrees with the range, null = «Eigene Daten»
 * @var list<\Z77\Module\Financial\Entities\Account>|null $accounts  the account statement's select
 * @var string $accountNumber
 * @var string $pdfForm   optional: the id of a GET form that carries the PDF's parameters itself —
 *                        the journal's list state, kept current by the list region (owner 2026-10-16:
 *                        the PDF prints what the screen shows); the button then submits THAT form
 */
$keep     = $keep ?? [];
$atDay    = $atDay ?? false;
$accounts = $accounts ?? null;
$year     = $range->year;
$min      = $year->getStartDate()->format('Y-m-d');
$max      = $year->getEndDate()->format('Y-m-d');
?>
<div class="be-list__section">
    <form method="get" action="<?= e($reportBase . '/' . $tab) ?>" class="be-form__grid be-form__grid--fill">
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
        <?php
        // The range select (owner 2026-10-22 — replaced FIN-UI-009's «Zeitraum»): a choice SETS
        // the dates — over a range «Ganzes Jahr», the halves, the quarters, the months; at a day
        // (the balance sheet) their ENDS for the «Stichtag» (`ReportPreset`). The option shown
        // is the one that agrees with the dates (`ReportPreset::selected()`), else «Eigene Daten» /
        // «Eigenes Datum». Without JavaScript the server resolves the choice on «Bildschirm»
        // (`shown_from` / `shown_to`: an edited date wins); with core.js the choice fills the
        // date fields at once and an edited date re-selects the matching choice
        // (`select[data-range-presets]`, the options carry their dates).
        $presets   = $presets ?? \Z77\Module\Financial\Reports\ReportPreset::of($year, $atDay);
        $presetKey = $presetKey ?? null;
        $grouped   = [];
        foreach ($presets as $preset) {
            $grouped[$preset->group][] = $preset;
        }
        $option = static function ($p) use ($presetKey): string {
            return '<option value="' . e($p->key) . '" data-from="' . e($p->from->format('Y-m-d')) . '" data-to="' . e($p->to->format('Y-m-d')) . '"'
                . ($p->key === $presetKey ? ' selected' : '') . '>' . e($p->label) . '</option>';
        };
        ?>
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
        <div class="be-form__field be-form__field--wide">
            <label for="report-period"><?= $atDay ? 'Auswahl' : 'Zeitraum' ?></label>
            <input type="hidden" name="shown_from" value="<?= e($atDay ? '' : $range->fromDay()) ?>">
            <input type="hidden" name="shown_to" value="<?= e($range->toDay()) ?>">
            <select id="report-period" name="period" data-range-presets data-range-to="report-to"<?= $atDay ? '' : ' data-range-from="report-from"' ?>>
                <option value=""<?= $presetKey === null ? ' selected' : '' ?>><?= $atDay ? 'Eigenes Datum' : 'Eigene Daten' ?></option>
                <?php foreach ($grouped as $group => $items): ?>
                <?php if ($group === ''): ?>
                <?php foreach ($items as $p): ?><?= $option($p) ?><?php endforeach; ?>
                <?php else: ?>
                <optgroup label="<?= e($group) ?>">
                    <?php foreach ($items as $p): ?><?= $option($p) ?><?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
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
                <?php if (in_array($tab, $pdfTabs ?? [], true) && ($pdfForm ?? '') !== ''): ?>
                <?php // The PDF of what the list below shows: the report's own GET form inside the list region carries its state (the journal). ?>
                <button type="submit" class="be-btn be-btn--ghost" form="<?= e($pdfForm) ?>" title="Als PDF in neuem Tab öffnen — wie am Bildschirm angezeigt">
                    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-download"/></svg> <span class="be-btn__label">PDF</span>
                </button>
                <?php elseif (in_array($tab, $pdfTabs ?? [], true)): ?>
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
