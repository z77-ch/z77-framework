<?php
/**
 * Finanzen › Änderungsprotokoll — every change and every deletion of a
 * journal entry (owner 2026-10-08: «nur ein Protokoll … es geht nur darum,
 * etwas nachvollziehen zu können»). READ-ONLY. A STANDARD LIST (listing.md):
 * the head and the search form are the kernel's partials over
 * {@see \Z77\Module\Financial\Ui\ChangeLogListing}, the rows are this
 * template's; no CSS and no JavaScript of its own:
 *
 *   - the state icon starts every row and opens the change (who, when, the
 *     entry before and after) as a WINDOW (ADR-047, `data-window-open`), the
 *     href stays for a ctrl-click and without the script;
 *   - every searchable cell is a `<label for>` of its column's search field;
 *   - the number shows its year when «Alle Jahre» is on (`?all=1`, the last
 *     entry of the year selection at the top of the rail);
 *   - the text is the entry's text BEFORE the change; an edit that changed it
 *     shows the new one after an arrow;
 *   - the whole list is a FETCH REGION: sort / page / search reload only
 *     this part.
 *
 * @var list<\Z77\Module\Financial\Entities\EntryChange> $changes
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year  the selected year — null without any year
 * @var array<string, string> $labels  change action → German label
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/change-log';
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$allYears   = $state->flag('all');
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Änderungsprotokoll
                <?php if ($year !== null): ?>
                <small class="be-list__cell--muted">· <?= $allYears ? 'alle Geschäftsjahre' : 'Geschäftsjahr ' . e($year->getCode()) ?></small>
                <?php endif; ?>
            </h2>
            <?php if ($state->isActive()): ?>
            <nav class="be-list__toggles" aria-label="Suche">
                <a data-fetch-region-link href="<?= e($listUrl . '?' . $state->resetQuery()) ?>">Suche zurücksetzen</a>
            </nav>
            <?php endif; ?>
            <span class="be-list__section-badge" title="Einträge"><?= $paging->total ?></span>
        </div>

        <?php if ($year === null): ?>
        <p class="be-list__empty">Noch kein Geschäftsjahr eröffnet — ohne Geschäftsjahr gibt es keine Buchung und nichts zu protokollieren.</p>
        <?php else: ?>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($changes as $change): ?>
                <?php
                $before  = $change->before();
                $after   = $change->after();
                $deleted = $after === null;
                $label   = $labels[$change->getAction()] ?? $change->getAction();
                $number  = ($allYears ? (string) ($before['fiscal_year'] ?? '') . '/' : '') . $change->getEntryNumber();
                $textNow = $after !== null && ($after['text'] ?? '') !== ($before['text'] ?? '') ? (string) ($after['text'] ?? '') : null;
                $detail  = $actionBase . '/detail?id=' . $change->getId();
                ?>
                <div class="be-list__item" data-change-id="<?= e((string) $change->getId()) ?>">
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state<?= $deleted ? ' be-list__state--deleted' : '' ?>" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>" aria-label="Änderung anzeigen" title="Vorher / nachher anzeigen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= $deleted ? 'trash' : 'edit' ?>"/></svg></a>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_at')) ?>" data-field="at"><?= e($change->getChangedAt()->format('d.m.Y H:i')) ?></label>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_nr')) ?>" data-field="number"><?= e($number) ?></label>
                        <span class="be-list__cell" data-priority="2" data-field="action"><span class="badge <?= $deleted ? 'badge--danger' : 'badge--warning' ?>"><?= e($label) ?></span></span>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_text')) ?>" data-field="text"><?= e((string) ($before['text'] ?? '')) ?><?= $textNow !== null ? ' <small class="be-list__cell--muted">→ ' . e($textNow) . '</small>' : '' ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_who')) ?>" data-priority="3" data-field="who"><?= e($change->getChangedBy()) ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($changes === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Eintrag gefunden.' : ($allYears ? 'Noch keine Buchung geändert oder gelöscht.' : 'In diesem Geschäftsjahr wurde noch keine Buchung geändert oder gelöscht.') ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Einträge',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
        <?php endif; ?>
    </div>
</div>
