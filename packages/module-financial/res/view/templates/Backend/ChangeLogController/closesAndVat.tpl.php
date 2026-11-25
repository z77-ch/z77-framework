<?php
/**
 * Finanzen › Änderungsprotokoll › «Abschlüsse und MWST» — the second tab (owner 2026-11-25, board
 * S2-9): every close and reopen of a fiscal year and every post and lift of a VAT return, newest
 * first. READ-ONLY. A STANDARD LIST (listing.md): the head and the search form are the kernel's
 * partials over {@see \Z77\Module\Financial\Ui\CloseProtocolListing}, the rows are this template's
 * (prepared by `ChangeLogControllerTrait::changeLogProtocolRows()`); no CSS and no JavaScript of its own:
 *
 *   - the voucher icon starts a VAT row (the archived PDF of that revision) — only while the
 *     installation offers the voucher action; otherwise the list has no such column;
 *   - every searchable cell is a `<label for>` of its column's search field; «Betrifft» links the
 *     VAT return of the period or the fiscal years;
 *   - «Grund / Angaben»: the reason of a lift or reopen in quotes, the rest muted; the full text
 *     (every confirmed warning) in the title;
 *   - the whole list is a FETCH REGION: sort / page / search reload only this part.
 *
 * @var list<array{kind: string, label: string, badge: string, at: string, who: string, subject: string, subjectHref: string, reason: ?string, info: string, title: string, voucher: ?string}> $rows
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year  the selected year — null without any year
 * @var bool $vouchers  whether the list has the voucher column
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/change-log';
$listUrl    = $actionBase . '/closes-and-vat';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$allYears   = $state->flag('all');
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
            <h2 class="be-list__section-title">
                Abschlüsse und MWST
                <?php if ($year !== null): ?>
                <small class="be-list__cell--muted">· <?= $allYears ? 'alle Geschäftsjahre' : 'Geschäftsjahr ' . e($year->getCode()) ?></small>
                <?php endif; ?>
            </h2>
            <span class="be-list__section-badge" title="Einträge"><?= $paging->total ?></span>
        </div>

        <?php if ($year === null): ?>
        <p class="be-list__empty">Noch kein Geschäftsjahr eröffnet — ohne Geschäftsjahr gibt es keinen Abschluss und keine MWST-Abrechnung.</p>
        <?php else: ?>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($rows as $row): ?>
                <div class="be-list__item" data-protocol-kind="<?= e($row['kind']) ?>">
                    <div class="be-list__row">
                        <?php if ($vouchers): ?>
                        <span class="be-list__cell"><?php if ($row['voucher'] !== null): ?><a class="be-icon-btn" href="<?= e($row['voucher']) ?>" target="_blank" rel="noopener" data-no-pending title="Beleg <?= e($row['subject']) ?>" aria-label="Beleg <?= e($row['subject']) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg></a><?php endif; ?></span>
                        <?php endif; ?>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_at')) ?>" data-field="at"><?= e($row['at']) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_who')) ?>" data-priority="3" data-field="who"><?= e($row['who']) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_what')) ?>" data-field="what"><span class="badge <?= e($row['badge']) ?>"><?= e($row['label']) ?></span></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_subject')) ?>" data-field="subject"><a href="<?= e($row['subjectHref']) ?>"><?= e($row['subject']) ?></a></label>
                        <span class="be-list__cell" data-priority="2" data-field="detail"<?= $row['title'] !== '' ? ' title="' . e($row['title']) . '"' : '' ?>><?php if ($row['reason'] !== null): ?>«<?= e($row['reason']) ?>»<?php endif; ?><?php if ($row['info'] !== ''): ?><?= $row['reason'] !== null ? ' ' : '' ?><small class="be-list__cell--muted"><?= $row['reason'] !== null ? '· ' : '' ?><?= e($row['info']) ?></small><?php endif; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Eintrag gefunden.' : ($allYears ? 'Noch kein Jahr abgeschlossen und keine MWST-Abrechnung gebucht.' : 'In diesem Geschäftsjahr wurde noch kein Jahr abgeschlossen und keine MWST-Abrechnung gebucht.') ?></p>
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
