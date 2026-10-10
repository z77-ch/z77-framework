<?php
/**
 * One row of the URL-alias list. Rendered by listAction.tpl.php AND by the in-place answer
 * of a create (`insertRow`, ADR-047 addendum 2026-10-10). `data-entity="navigationAlias:<id>"`
 * addresses the row; the `data-field` cells take the `update-fields` answer of an edit.
 *
 * @var array{id:?int, path:string, navLabel:string, flags:string, active:bool} $row
 *      NavigationAliasController::aliasDisplay()
 */
?>
<div class="be-tree__node<?= $row['active'] ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0"
     data-alias-id="<?= e($row['id']) ?>" data-entity="navigationAlias:<?= e($row['id']) ?>">
    <div class="be-tree__row">
        <span class="be-tree__toggle" aria-hidden="true"></span>
        <label class="be-switch be-switch--sm be-tree__switch" title="Aktiv schalten">
            <input type="checkbox" class="be-switch__input"
                   data-fetch-toggle="/backend/content/navigation-alias/toggle-active?id=<?= e($row['id']) ?>"<?= $row['active'] ? ' checked' : '' ?>>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        </label>
        <button type="button" class="be-tree__menu" title="Aktionen"
                data-fetch-get="/backend/content/navigation-alias/actions?id=<?= e($row['id']) ?>">⋮</button>
        <span class="be-tree__name"><code data-field="path"><?= e($row['path']) ?></code></span>
        <span class="be-tree__url" data-field="nav_label"><?= e($row['navLabel']) ?></span>
        <span class="be-tree__route" data-field="flags"><?= raw($row['flags']) ?></span>
    </div>
</div>
