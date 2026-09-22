<?php
/**
 * The parameters of a report page (financial.md, P2 part 3): a GET form —
 * the fiscal year travels as a hidden field (the year is switched in hc2,
 * whose links carry no dates, so a date of another year never arrives
 * here), from and to (the balance sheet only «Stichtag»), the account on the
 * account statement — and the months of the year as shortcut links that set
 * from–to. Then what was not usable in the request. Screen only
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
        <div class="be-form__field">
            <button type="submit" class="be-btn be-btn--primary">Anzeigen</button>
        </div>
    </form>
    <p class="be-form__hint">
        <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($link($tab, $keep + ['from' => $min, 'to' => $max])) ?>">Ganzes Jahr</a>
        <?php foreach ($year->getPeriods() as $period): ?>
        <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($link($tab, $keep + ['from' => $period->getStartDate()->format('Y-m-d'), 'to' => $period->getEndDate()->format('Y-m-d')])) ?>"><?= e($months[(int) $period->getStartDate()->format('n') - 1] . ' ' . $period->getStartDate()->format('Y')) ?></a>
        <?php endforeach; ?>
    </p>
    <?php if (($notices ?? []) !== []): ?>
    <div class="be-modal__alert be-modal__alert--error">
        <?php foreach ($notices as $notice): ?>
        <div><?= e($notice) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
