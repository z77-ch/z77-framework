<?php
/**
 * The fiscal-year selection of the finance area — at the TOP OF THE RAIL (body
 * section `railSelect`, owner 2026-10-08, ADR-033 rev.: a choice that holds for the
 * whole area stands above the menu entries it applies to; it was in the action
 * cell from 2026-09-29). The journal and the reports add it
 * (`addPartials('fiscalYearSelect', 'Backend/partials', …, 'railSelect')`),
 * the context comes from {@see \Z77\Module\Financial\Ui\FiscalYearSelection}.
 *
 * A CSS-only dropdown (Rule 7): `<details>`/`<summary>` with the `.be-shell-add`
 * picker anatomy; every year is a plain link to the current screen with
 * `?year=<code>` — the server remembers the choice for the session. No JavaScript.
 * The value in the summary (`.be-shell-select__value`) is drawn in the accent; the list
 * opens as the floating shell card (css-backend.md, rail-top selection).
 *
 * Optional last entry «Alle Jahre», after a separator line (`allHref`, `allOn`): a
 * screen that can show ALL years — the journal list, `?all=1` (owner 2026-10-08, was the «alle Geschäftsjahre»
 * toggle in the list header). While it is on, the summary reads «Alle Jahre» and no
 * single year is marked current. The reports pass neither.
 *
 * @var array{years: list<\Z77\Module\Financial\Entities\FiscalYear>, current: \Z77\Module\Financial\Entities\FiscalYear, href: callable(string): string, allHref?: string, allOn?: bool} $fySelection
 */
if (empty($fySelection['years']) || !isset($fySelection['current'])) { return; }
$allHref = $fySelection['allHref'] ?? null;
$allOn   = $allHref !== null && !empty($fySelection['allOn']);
$current = $allOn ? null : $fySelection['current']->getCode();
?>
<details class="be-shell-add be-shell-add--select">
    <summary class="be-btn be-btn--ghost" aria-label="Geschäftsjahr wählen">
        <span class="be-btn__label"><?php if ($allOn): ?><span class="be-shell-select__value">Alle Jahre</span><?php else: ?>Geschäftsjahr <span class="be-shell-select__value"><?= e($current) ?></span><?php endif; ?></span>
        <svg class="be-icon be-shell-add__chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </summary>
    <nav class="be-shell-add__panel" aria-label="Geschäftsjahre">
        <?php foreach ($fySelection['years'] as $candidate): $code = $candidate->getCode(); ?>
        <a class="be-shell-add__item" href="<?= e(($fySelection['href'])($code)) ?>"<?= $code === $current ? ' aria-current="true"' : '' ?>><?= e($code) ?></a>
        <?php endforeach; ?>
        <?php if ($allHref !== null): ?>
        <hr class="be-shell-add__sep">
        <a class="be-shell-add__item" href="<?= e($allHref) ?>"<?= $allOn ? ' aria-current="true"' : '' ?>>Alle Jahre</a>
        <?php endif; ?>
    </nav>
</details>
