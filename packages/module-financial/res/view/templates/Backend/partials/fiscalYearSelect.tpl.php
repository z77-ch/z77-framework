<?php
/**
 * The fiscal-year selection of a finance work screen — the action cell (hc1),
 * owner 2026-09-29: choosing the year is a selection activity. The journal and
 * the reports add it (`addPartials('fiscalYearSelect', 'Backend/partials', …, 'hc1')`),
 * the context comes from {@see \Z77\Module\Financial\Ui\FiscalYearSelection}.
 *
 * A CSS-only dropdown (Rule 7): `<details>`/`<summary>` with the `.be-shell-add`
 * picker anatomy; every year is a plain link to the current screen with
 * `?year=<code>` — the server remembers the choice for the session. No JavaScript.
 *
 * @var array{years: list<\Z77\Module\Financial\Entities\FiscalYear>, current: \Z77\Module\Financial\Entities\FiscalYear, href: callable(string): string} $fySelection
 */
if (empty($fySelection['years']) || !isset($fySelection['current'])) { return; }
$current = $fySelection['current']->getCode();
?>
<details class="be-shell-add be-shell-add--select">
    <summary class="be-btn be-btn--ghost" aria-label="Geschäftsjahr wählen">
        <span class="be-btn__label">Geschäftsjahr <?= e($current) ?></span>
        <svg class="be-icon be-shell-add__chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </summary>
    <nav class="be-shell-add__panel" aria-label="Geschäftsjahre">
        <?php foreach ($fySelection['years'] as $candidate): $code = $candidate->getCode(); ?>
        <a class="be-shell-add__item" href="<?= e(($fySelection['href'])($code)) ?>"<?= $code === $current ? ' aria-current="true"' : '' ?>><?= e($code) ?></a>
        <?php endforeach; ?>
    </nav>
</details>
