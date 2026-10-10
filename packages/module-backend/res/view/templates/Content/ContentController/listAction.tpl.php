<?php
/** @var array<int, array{content:\Z77\Shared\Entities\Content, id:string, name:string, meta:string, qs:string}> $rows
 *       ContentController::rowDisplay(), sorted: slug, live first, variants by key, versions newest first */
/** @var string $editLanguage   the active content-editing language (session-sticky) */
/** @var array<int,string> $editLanguages   all servable languages (config/i18n) */

// Rows render through `_row` — the same partial the in-place answers render (ADR-047
// addendum 2026-10-10). The container is `data-entity-list="content"`: a create appends
// to it; the empty notice carries `data-entity-empty` so the first insert can remove it.
?>
<?php /* Content-header (language switcher + title + add) moved to the shell hc2 slot:
         Content/ContentController/hc2.tpl.php (added as the `contentHead` section in listAction). */ ?>
<div class="be-list">
    <section class="be-list__section">
        <div class="be-tree be-tree--hub" data-entity-list="content">
            <?php if (empty($rows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem" data-entity-empty="content">Noch keine Inhalte vorhanden.</p>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
            <?= $this->partial('Content/ContentController/_row', ['row' => $row], 'Z77\\Module\\Backend') ?>
            <?php endforeach; ?>
        </div>
    </section>
</div>
