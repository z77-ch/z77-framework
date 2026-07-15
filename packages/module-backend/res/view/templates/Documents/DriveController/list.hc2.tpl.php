<?php
/**
 * Drive list — hc2 (middle slot): the current folder PATH (breadcrumb PANE, live-refreshed in place
 * by drive.js) on the left + the remaining Drive actions (new folder, trash) as icons on the right —
 * styled like every other backend list header. Auto-loaded into the shell header band.
 *
 * The breadcrumb stays the DMS `_breadcrumb` partial (unchanged) so {@see DriveControllerTrait::panes}
 * can keep replacing `.dms-drive__breadcrumb` after every folder navigation; the `.dms` wrapper supplies
 * the `--dms-*` tokens it reads. `data-drive-scope` marks this out-of-fragment slot as part of the Drive
 * so drive.js handles the crumb links + the folder-add / trash buttons (which read their server-built
 * URLs off the breadcrumb pane). The action glyphs use the backend sprite / `.be-icon-btn`, matching the
 * other views. Rendered with the action view model, so all breadcrumb vars are in scope.
 *
 * @var string   $tplNs
 * @var string   $base
 * @var bool     $rootActive
 * @var string   $rootLabel
 * @var int|null $rootFolderId
 * @var array    $crumbs
 * @var int|null $selectedFolderId
 * @var array|null $selectedDoc
 */
?>
<div class="dms be-drive-head" data-drive-scope>
    <?= $this->partial('Documents/DriveController/_breadcrumb', [
        'rootActive'       => $rootActive,
        'rootLabel'        => $rootLabel,
        'rootFolderId'     => $rootFolderId,
        'crumbs'           => $crumbs,
        'selectedFolderId' => $selectedFolderId,
        'selectedDoc'      => $selectedDoc,
        'base'             => $base,
    ], $tplNs) ?>
    <button type="button" class="be-icon-btn" data-drive-folder-add title="Neuer Ordner">
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-folder-plus"/></svg>
    </button>
    <button type="button" class="be-icon-btn" data-drive-trash title="Papierkorb">
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-trash"/></svg>
    </button>
</div>
