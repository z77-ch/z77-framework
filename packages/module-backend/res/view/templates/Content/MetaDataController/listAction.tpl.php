<?php
/** @var array<int,array{key:string,label:string,rows:array<int,array{page:\Z77\Shared\Entities\Navigation,meta:?\Z77\Shared\Entities\MetaData}>}> $groups */
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
<?php /* Language switcher moved to the shell header band (list.hc2.tpl.php, auto-loaded); the
         breadcrumb/title were dropped (module switcher shows the section). The environment filter
         (tabs) stays in the body — it filters the list below. */ ?>
<?php if (count($environments) > 1): ?>
<div class="be-tabs" style="padding:14px 32px 0">
    <a class="be-tabs__tab<?= $envFilter === '' ? ' be-tabs__tab--active' : '' ?>" href="<?= e($base) ?>">Alle</a>
    <?php foreach ($environments as $env): ?>
    <a class="be-tabs__tab<?= $envFilter === $env['key'] ? ' be-tabs__tab--active' : '' ?>"
       href="<?= e($base . '?env=' . rawurlencode($env['key'])) ?>"><?= e($env['label']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
        <div class="be-tree be-tree--hub">
            <?php if (empty($group['rows'])): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine routbaren Seiten in dieser Umgebung.</p>
            <?php endif; ?>

            <?php foreach ($group['rows'] as $row):
                $page = $row['page'];
                $meta = $row['meta'];
                $has  = $meta !== null;
                $status = $has
                    ? '✓ vorhanden' . ($meta->getTitle() !== '' ? ' · ' . $meta->getTitle() : '')
                    : '✗ fehlt';
            ?>
            <div class="be-tree__node<?= $has ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/meta-data/actions?navigation_id=<?= e((string)$page->getId()) ?>">⋮</button>
                    <span class="be-tree__name"><?= e(t('nav.' . $page->getAction(), [], $editLanguage)) ?></span>
                    <span class="be-tree__url"><?= e($page->getUrl()) ?></span>
                    <span class="be-tree__route"><?= e($status) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
