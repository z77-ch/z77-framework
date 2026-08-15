<?php
/**
 * Drive list — hc3 (crumb line): the current folder PATH, and only the path
 * (ADR-033: the crumb line carries position and state; the folder tools sit in
 * hc2 now). The breadcrumb stays the DMS `_breadcrumb` partial so
 * {@see DriveControllerTrait::panes} keeps replacing `.dms-drive__breadcrumb`
 * in place after every folder navigation; the `.dms` wrapper supplies the
 * `--dms-*` tokens it reads. `data-drive-scope` marks this out-of-fragment
 * slot as part of the Drive for drive.js (crumb links, tool-URL attributes).
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots(); rendered with
 * the action view model, so all breadcrumb vars are in scope.
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
</div>
