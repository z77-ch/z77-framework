<?php
/** @var array<int,array{key:string,label:string,rows:array<int,array{page:\Z77\Shared\Entities\Navigation,meta:?\Z77\Shared\Entities\MetaData,status:string}>}> $groups */
/** @var array<int,array{key:string,label:string}> $environments  public view areas for the filter bar */
/** @var string $envFilter  active environment name, or '' for all */
/** @var string $editLanguage   the active content-editing language (session-sticky) */
/** @var array<int,string> $editLanguages   all servable languages (config/i18n) */

$base    = '/backend/content/meta-data/list';
$total   = 0;
$present = 0;
foreach ($groups as $g) {
    foreach ($g['rows'] as $r) {
        $total++;
        if ($r['meta'] !== null) { $present++; }
    }
}
?>
<?php /* Language switcher AND environment filter live in the shell toolbar (list.hc2.tpl.php,
         auto-loaded): tabs, ADR-033 rev. 2026-10-08. The breadcrumb/title were dropped (module
         switcher shows the section). */ ?>
<div class="be-list">
    <?php if (empty($groups)): ?>
    <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine public Umgebung mit routbaren Seiten vorhanden.</p>
    <?php endif; ?>

    <?php foreach ($groups as $group): ?>
    <section class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($group['label']) ?></h2>
            <span class="be-list__section-badge"><?= count($group['rows']) ?> Seite<?= count($group['rows']) === 1 ? '' : 'n' ?></span>
        </div>
        <div class="be-tree be-tree--hub be-tree--lead-menu">
            <?php if (empty($group['rows'])): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine routbaren Seiten in dieser Umgebung.</p>
            <?php endif; ?>

            <?php foreach ($group['rows'] as $row):
                $page = $row['page'];
                $has  = $row['meta'] !== null;
                // One row per page, keyed by its navigation entry: a save / delete answers
                // in place on this row (MetaDataController::pageRowUpdate, `data-field="status"`).
            ?>
            <div class="be-tree__node<?= $has ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0"
                 data-entity="navigation:<?= e((string)$page->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/meta-data/actions?navigation_id=<?= e((string)$page->getId()) ?>">⋮</button>
                    <span class="be-tree__name"><?= e(t('nav.' . $page->getAction(), [], $editLanguage)) ?></span>
                    <span class="be-tree__url"><?= e($page->getUrl()) ?></span>
                    <span class="be-tree__route" data-field="status"><?= e($row['status']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
