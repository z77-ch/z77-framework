<?php
/**
 * The journal list — the lower half of the journal page (FIN-JOURNAL-CAPTURE-001,
 * owner 2026-09-28; the capture form above it is `oneLine` / `form`). Newest
 * first, paged by server links, searchable per column in the database:
 *
 *   - every header cell of a searchable column is a `.be-list__find`: the title
 *     sorts (a link, `?sort=` / `?dir=`), the magnifier beside it opens the
 *     search field right there (a `<label for>` that focuses a collapsed input
 *     — no JavaScript). All fields belong to the GET form `#journal-find`;
 *     Enter searches, over one fiscal year or — «Alle Jahre», the last entry
 *     of the year dropdown at the top of the rail (owner 2026-10-08; was a
 *     toggle here) — all;
 *   - a state icon starts every row: editable · generated · closed · VAT
 *     settled (`$states`). Deleted numbers are NOT shown here (owner
 *     2026-10-08: «gelöschte zeigen macht keinen Sinn») — every change and
 *     deletion is in the change log, Finanzen › Änderungsprotokoll;
 *   - the whole list is a FETCH REGION (`data-fetch-region="journal-list"`,
 *     core.js): sort / page links and the search form reload only
 *     this part, the capture form above keeps what is typed (owner
 *     2026-09-28). Without the script they are plain page loads;
 *   - the state icon is the ONLY way into an entry (owner 2026-09-29): it
 *     opens a window (ADR-047, `data-window-open`) — an editable entry its
 *     edit form, any other its detail; the journal stays, the href remains
 *     for a ctrl-click and for a browser without the script;
 *   - every other cell of an entry is a `<label for>` of its column's search
 *     field: a click opens that search (owner 2026-09-29) — no JavaScript.
 *
 * Styling: the shared backend list v2 classes plus `.be-list__find`,
 * `.be-list__state` (module-backend `_list.scss`) — no CSS
 * and no JavaScript of its own.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year  the SELECTED year (FiscalYearSelection, owner 2026-09-29) — null without any year
 * @var list<array{entry: \Z77\Module\Financial\Entities\JournalEntry, state: string, number: int}> $rows
 * @var \Z77\Module\Financial\Ui\JournalFilter $filter
 * @var \Z77\Shared\Paging\Paging $paging
 * @var array<string, string> $keep    the capture state (`mode`, `date`) every link carries
 * @var array<string, string> $states  state key → German title
 * @var callable $fmt  Money → «1'234.50»
 * @var string|null $configNotice
 * @var string $actionBase
 */
use Z77\Module\Financial\Ui\JournalFilter;

$actionBase = $actionBase ?? '/backend/finance/journal';
$ns         = 'Z77\\Module\\Financial';
$link       = static fn(array $changes = []): string => $actionBase . '/list?'
    . ltrim(http_build_query($keep) . '&' . $filter->query($changes), '&');
$icons      = ['editable' => 'edit', 'generated' => 'zap', 'closed' => 'lock', 'vat-settled' => 'lock'];
/** The account numbers on one side of an entry — «div.» beyond two. */
$side = static function (\Z77\Module\Financial\Entities\JournalEntry $e, bool $debit): string {
    $numbers = [];
    foreach ($e->getLines() as $line) {
        if (($debit ? $line->getDebit() : $line->getCredit())->isPositive()) {
            $numbers[$line->getAccount()->getNumber()] = true;
        }
    }
    return count($numbers) > 2 ? 'div.' : implode(', ', array_keys($numbers));
};
/** The sort link of a column: the current column flips its direction; a new one starts newest / largest first, the text A–Z. */
$sortLink = static function (string $sort) use ($filter, $link): string {
    $descending = $filter->sort === $sort ? !$filter->descending : $sort !== 'text';

    return $link(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
};
$cols    = '2rem 4.5rem 6.5rem minmax(10rem, 2fr) 6rem 6rem 8rem';
$colsSm  = '2rem 4rem minmax(7rem, 2fr) 5rem 5rem 6rem';   // fits ~470px — checked at phone width, 2026-09-28
$colsXs  = '2rem 4rem minmax(8rem, 2fr) 7rem';
$priority = ['f_date' => '3', 'f_debit' => '2', 'f_credit' => '2'];
?>
<div class="be-list" data-fetch-region="journal-list">
    <?php if ($year === null): ?>
    <?php if (!empty($configNotice)): ?>
    <div class="be-modal__alert be-modal__alert--error"><?= e($configNotice) ?></div>
    <?php endif; ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Journal</h2>
            <span class="be-list__section-badge">0</span>
        </div>
        <p class="be-list__empty">Noch kein Geschäftsjahr eröffnet — ohne Geschäftsjahr kann nichts gebucht werden.
           <a href="/backend/finance/fiscal-year/list">Geschäftsjahre</a></p>
    </div>
    <?php else: ?>
    <div class="be-list__section" data-fiscal-year-id="<?= e((string) $year->getId()) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Buchungen
                <small class="be-list__cell--muted">· <?= $filter->allYears ? 'alle Geschäftsjahre' : 'Geschäftsjahr ' . e($year->getCode()) ?></small>
            </h2>
            <?php if ($filter->isActive()): ?>
            <nav class="be-list__toggles" aria-label="Suche">
                <a data-fetch-region-link href="<?= e($link(array_fill_keys(array_keys(JournalFilter::FIELDS), null))) ?>">Suche zurücksetzen</a>
            </nav>
            <?php endif; ?>
            <span class="be-list__section-badge" title="Buchungen"><?= $paging->total ?></span>
        </div>

        <form id="journal-find" method="get" action="<?= e($actionBase) ?>/list" role="search" data-fetch-region-form>
            <?php foreach ($keep + $filter->hiddenState() as $name => $value): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
            <button type="submit" class="be-list__find-submit" tabindex="-1">Suchen</button>
        </form>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: <?= $cols ?>; --be-list-cols-sm: <?= $colsSm ?>; --be-list-cols-xs: <?= $colsXs ?>">
                <div class="be-list__head">
                    <span class="be-list__col" aria-label="Status"></span>
                    <?php foreach (JournalFilter::FIELDS as $key => $label): ?>
                    <?php
                    $sort    = JournalFilter::SORT_OF[$key] ?? null;
                    $numeric = in_array($key, ['f_nr', 'f_amount'], true);
                    $class   = 'be-list__col be-list__find' . ($numeric ? ' be-list__col--num' : '') . ($filter->value($key) !== '' ? ' be-list__find--active' : '');
                    ?>
                    <span class="<?= $class ?>"<?= isset($priority[$key]) ? ' data-priority="' . $priority[$key] . '"' : '' ?>>
                        <?php if ($sort !== null): ?>
                        <a class="be-list__sort" data-fetch-region-link href="<?= e($sortLink($sort)) ?>"<?= $filter->sort === $sort ? ' data-sort="' . ($filter->descending ? 'desc' : 'asc') . '"' : ' data-sort' ?>><?= e($label) ?></a>
                        <?php else: ?>
                        <span class="be-list__find-label"><?= e($label) ?></span>
                        <?php endif; ?>
                        <label class="be-list__find-icon" for="journal-find-<?= e($key) ?>" title="<?= e($label) ?> suchen">
                            <svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-search"/></svg>
                        </label>
                        <input class="be-list__find-input" type="search" id="journal-find-<?= e($key) ?>" name="<?= e($key) ?>" form="journal-find"
                               value="<?= e($filter->value($key)) ?>" placeholder=" " autocomplete="off" aria-label="<?= e($label) ?> suchen"
                               aria-invalid="<?= $filter->isInvalid($key) ? 'true' : 'false' ?>"<?= $key === 'f_debit' || $key === 'f_credit' ? ' inputmode="numeric"' : '' ?>>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($rows as $row): ?>
                <?php $entry = $row['entry']; ?>
                <div class="be-list__item" data-entry-id="<?= e((string) $entry->getId()) ?>">
                    <div class="be-list__row">
                        <?php $stateUrl = $actionBase . ($row['state'] === 'editable' ? '/edit' : '/detail') . '?id=' . $entry->getId(); ?>
                        <a class="be-list__cell be-list__state be-list__state--<?= e($row['state']) ?>" href="<?= e($stateUrl) ?>" data-window-open="<?= e($stateUrl) ?>" aria-label="<?= e($states[$row['state']]) ?>" title="<?= e($states[$row['state']]) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= e($icons[$row['state']]) ?>"/></svg></a>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="journal-find-f_nr"><?= $filter->allYears ? e($entry->getFiscalYear()->getCode()) . '/' : '' ?><?= $entry->getNumber() ?></label>
                        <label class="be-list__cell" for="journal-find-f_date" data-priority="3"><?= e($entry->getDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="journal-find-f_text"><?= e($entry->getText()) ?><?= $entry->isReversal() ? ' <span class="badge badge--warning">Storno von ' . e($entry->getReversalOf()->getFiscalYear()->getCode() . '/' . $entry->getReversalOf()->getNumber()) . '</span>' : '' ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="journal-find-f_debit" data-priority="2"><?= e($side($entry, true)) ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="journal-find-f_credit" data-priority="2"><?= e($side($entry, false)) ?></label>
                        <label class="be-list__cell be-list__cell--num" for="journal-find-f_amount"><?= e($fmt($entry->total())) ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty"><?= $filter->isActive() ? 'Keine Buchung gefunden.' : ($filter->allYears ? 'Noch keine Buchung.' : 'Noch keine Buchung in diesem Geschäftsjahr.') ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'   => $paging,
            'pageLink' => static fn(int $p): string => $link(['page' => $p]),
            'unit'     => 'Buchungen',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
    <?php endif; ?>
</div>
