<?php
/** @var \Z77\Shared\Entities\Content $content */
/** @var bool $isNew */
/** @var array<int,string> $knownTypes */
/** @var array<string,array<int,array<string,mixed>>> $schemas  type => field descriptors */
/** @var string $entityCsrf */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */
/** @var string $rawBlocks */

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};

// ── block field renderers (shared by existing blocks + the per-type templates) ──

// One scalar input (text | textarea | url | select | bool). $bfAttr is the
// data-attribute the editor JS reads ('data-bf' for top-level, 'data-bf-sub'
// inside an object list row). Sub-fields are always read as plain strings.
$ceInput = function (array $f, mixed $value, string $bfAttr): string {
    $key   = (string)($f['key'] ?? '');
    $kind  = (string)($f['kind'] ?? 'text');
    $label = (string)($f['label'] ?? $key);
    $bf    = $bfAttr . '="' . e($key) . '" data-bk="' . e($kind) . '"';

    if ($kind === 'textarea') {
        return '<label class="ce-field"><span class="ce-field__label">' . e($label) . '</span>'
            . '<textarea ' . $bf . ' rows="3" spellcheck="false">' . e((string)$value) . '</textarea></label>';
    }
    if ($kind === 'select') {
        $opts = '';
        foreach ((array)($f['options'] ?? []) as $optVal => $optLabel) {
            $sel = ((string)$optVal === (string)$value) ? ' selected' : '';
            $opts .= '<option value="' . e((string)$optVal) . '"' . $sel . '>' . e((string)$optLabel) . '</option>';
        }
        return '<label class="ce-field"><span class="ce-field__label">' . e($label) . '</span>'
            . '<select ' . $bf . '>' . $opts . '</select></label>';
    }
    if ($kind === 'bool') {
        $checked = $value ? ' checked' : '';
        return '<label class="ce-field ce-field--bool"><input type="checkbox" ' . $bf . $checked . '>'
            . '<span class="ce-field__label">' . e($label) . '</span></label>';
    }
    // text | url (and any unknown kind) → single-line input
    $type = $kind === 'url' ? 'url' : 'text';
    return '<label class="ce-field"><span class="ce-field__label">' . e($label) . '</span>'
        . '<input type="' . $type . '" ' . $bf . ' value="' . e((string)$value) . '" autocomplete="off"></label>';
};

// One scalar-list row (<input data-bv>) — markup shared by existing rows + the
// row template the JS clones.
$ceScalarRow = function (string $value = ''): string {
    return '<div class="ce-row" data-ce-row>'
        . '<input type="text" data-bv value="' . e($value) . '" autocomplete="off">'
        . '<button type="button" class="be-icon-btn be-icon-btn--danger" data-ce-row-remove title="Entfernen">&times;</button>'
        . '</div>';
};

// One object-list row (sub-fields via data-bf-sub).
$ceObjectRow = function (array $itemSchema, array $values) use ($ceInput): string {
    $fields = '';
    foreach ($itemSchema as $sub) {
        $fields .= $ceInput($sub, $values[$sub['key']] ?? '', 'data-bf-sub');
    }
    return '<div class="ce-row ce-row--object" data-ce-row>'
        . '<div class="ce-row__fields">' . $fields . '</div>'
        . '<button type="button" class="be-icon-btn be-icon-btn--danger" data-ce-row-remove title="Entfernen">&times;</button>'
        . '</div>';
};

// One block field (dispatches scalar vs list).
$ceField = function (array $f, array $values) use ($ceInput, $ceScalarRow, $ceObjectRow): string {
    $key  = (string)($f['key'] ?? '');
    $kind = (string)($f['kind'] ?? 'text');

    if ($kind !== 'list') {
        $default = $f['default'] ?? '';
        return $ceInput($f, $values[$key] ?? $default, 'data-bf');
    }

    $item     = $f['item'] ?? 'text';
    $isObject = is_array($item);
    $rowsData = is_array($values[$key] ?? null) ? $values[$key] : [];

    $rows = '';
    $tpl  = '';
    if ($isObject) {
        foreach ($rowsData as $row) {
            $rows .= $ceObjectRow($item, is_array($row) ? $row : []);
        }
        $tpl = $ceObjectRow($item, []);
    } else {
        foreach ($rowsData as $row) {
            $rows .= $ceScalarRow((string)$row);
        }
        $tpl = $ceScalarRow('');
    }

    return '<div class="ce-field ce-list" data-bf="' . e($key) . '" data-bk="list" data-litem="' . ($isObject ? 'object' : 'scalar') . '">'
        . '<span class="ce-field__label">' . e((string)($f['label'] ?? $key)) . '</span>'
        . '<div class="ce-list__rows" data-ce-rows>' . $rows . '</div>'
        . '<template data-ce-row-tpl>' . $tpl . '</template>'
        . '<button type="button" class="be-btn be-btn--ghost be-btn--sm" data-ce-row-add>+ Eintrag</button>'
        . '</div>';
};

// A full block card (header tools + body fields). $values = the block's data.
$ceBlock = function (string $type, array $schema, array $values) use ($ceField): string {
    $body = '';
    foreach ($schema as $f) {
        $body .= $ceField($f, $values);
    }
    return '<div class="ce-block" data-ce-block data-type="' . e($type) . '">'
        . '<header class="ce-block__head">'
        . '<span class="ce-block__type">' . e($type) . '</span>'
        . '<div class="ce-block__tools">'
        . '<button type="button" class="be-icon-btn" data-ce-up title="Nach oben">&uarr;</button>'
        . '<button type="button" class="be-icon-btn" data-ce-down title="Nach unten">&darr;</button>'
        . '<button type="button" class="be-icon-btn be-icon-btn--danger" data-ce-remove title="Block entfernen">&times;</button>'
        . '</div></header>'
        . '<div class="ce-block__body">' . $body . '</div>'
        . '</div>';
};

// An unknown-type block (no schema): keep its data verbatim so editing other
// blocks never drops it. JS reads data-ce-raw instead of fields.
$ceUnknownBlock = function (array $block): string {
    $type = (string)($block['type'] ?? '?');
    $json = json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '<div class="ce-block ce-block--unknown" data-ce-block data-type="' . e($type) . '" data-ce-raw="' . e($json) . '">'
        . '<header class="ce-block__head">'
        . '<span class="ce-block__type">' . e($type) . ' (unbekannt)</span>'
        . '<div class="ce-block__tools">'
        . '<button type="button" class="be-icon-btn be-icon-btn--danger" data-ce-remove title="Block entfernen">&times;</button>'
        . '</div></header>'
        . '<div class="ce-block__body"><pre class="ce-block__raw">' . e((string)$json) . '</pre></div>'
        . '</div>';
};
?>
<form data-fetch-post>
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Neuer Inhalt' : 'Inhalt bearbeiten' ?></h2>
        <span class="be-lang-tag" title="Bearbeitungssprache"><?= e(strtoupper($content->getLanguage())) ?></span>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">Bitte überprüfe die markierten Eingaben.</div>
        <?php endif; ?>

        <div class="be-modal__switches">
            <label class="be-switch">
                <input type="checkbox" class="be-switch__input" name="active" value="1"<?= $content->isActive() ? ' checked' : '' ?>>
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                <span class="be-switch__label">Aktiv <small>(inaktiver Inhalt wird im Frontend nicht gerendert)</small></span>
            </label>
        </div>

        <div class="be-form__grid" style="grid-template-columns:1fr 1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Slug</label>
                <input type="text" name="slug" value="<?= e($content->getSlug()) ?>" required autocomplete="off"
                       placeholder="z.B. home" <?= $isNew ? '' : 'disabled' ?>
                       aria-invalid="<?= $validator->hasFieldError('slug') ? 'true' : 'false' ?>">
                <?= raw($fieldError('slug')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Sprache</label>
                <input type="text" name="language" value="<?= e($content->getLanguage()) ?>" autocomplete="off" disabled
                       aria-invalid="<?= $validator->hasFieldError('language') ? 'true' : 'false' ?>">
                <small class="be-form__hint"><?= $isNew ? 'Folgt der Bearbeitungssprache' : 'Sprache ist unveränderlich' ?></small>
                <?= raw($fieldError('language')) ?>
            </div>
        </div>

        <div class="be-form__field" data-z77-field-wrapper>
            <label>Titel</label>
            <input type="text" name="title" value="<?= e($content->getTitle()) ?>" required autocomplete="off"
                   aria-invalid="<?= $validator->hasFieldError('title') ? 'true' : 'false' ?>">
            <?= raw($fieldError('title')) ?>
        </div>

        <div class="be-form__field" data-z77-field-wrapper>
            <label>Blöcke</label>
            <?php if ($validator->hasFieldError('blocks')): ?>
                <?= raw($fieldError('blocks')) ?>
            <?php endif; ?>

            <div class="ce" data-ce-editor>
                <input type="hidden" name="blocks" data-ce-json>

                <div class="ce__blocks" data-ce-blocks>
                    <?php foreach ($content->getBlocks() as $block):
                        if (!is_array($block)) { continue; }
                        $type = (string)($block['type'] ?? '');
                        if ($type !== '' && isset($schemas[$type])):
                            echo $ceBlock($type, $schemas[$type], $block);
                        else:
                            echo $ceUnknownBlock($block);
                        endif;
                    endforeach; ?>
                </div>

                <p class="ce__empty" data-ce-empty<?= $content->getBlocks() !== [] ? ' hidden' : '' ?>>
                    Noch keine Blöcke. Wähle einen Typ und füge ihn hinzu.
                </p>

                <div class="ce-add">
                    <select class="ce-add__type" data-ce-add-type aria-label="Block-Typ">
                        <?php foreach ($knownTypes as $t): ?>
                        <option value="<?= e($t) ?>"><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="be-btn be-btn--ghost" data-ce-add>+ Block hinzufügen</button>
                </div>

                <details class="ce-preview">
                    <summary>JSON-Vorschau</summary>
                    <pre data-ce-preview></pre>
                </details>

                <!-- Empty per-type block templates the editor JS clones on "add". -->
                <div data-ce-templates hidden>
                    <?php foreach ($schemas as $type => $schema): ?>
                    <template data-ce-tpl="<?= e($type) ?>"><?= $ceBlock($type, $schema, []) ?></template>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
