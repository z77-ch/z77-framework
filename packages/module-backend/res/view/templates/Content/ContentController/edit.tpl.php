<?php
/** @var \Z77\Shared\Entities\Content $content */
/** @var bool $isNew */
/** @var array<int,string> $knownTypes */
/** @var array<string,array<int,array<string,mixed>>> $schemas  type => field descriptors */
/** @var \Z77\Shared\Content\Blueprint|null $blueprint  fixed slots (ADR-044), null = free block stream */
/** @var array<string,array<string,mixed>> $actions  project actions for `action:` links */
/** @var string $entityCsrf */
/** @var string $entityHash  optimistic lock: hash of the state this form was rendered from */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */
/** @var string $rawBlocks */

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};

// ── block field renderers (shared by existing blocks + the per-type templates) ──

// Which inline formatting a field allows (descriptor `inline` / `links`, ADR-044),
// as a short hint under the input. No `inline` → nothing to show: plain text.
$ceHint = function (array $f) use ($actions): string {
    $inline = is_array($f['inline'] ?? null) ? $f['inline'] : [];
    if ($inline === []) {
        return '';
    }
    $parts = [];
    if (in_array('bold', $inline, true))   { $parts[] = '**fett**'; }
    if (in_array('italic', $inline, true)) { $parts[] = '*kursiv*'; }
    if (in_array('link', $inline, true)) {
        $targets = (array)($f['links']['targets'] ?? ['page', 'media', 'external', 'mailto']);
        if (array_intersect($targets, ['page', 'media', 'external', 'mailto']) !== []) {
            $parts[] = '[Text](/seite)';
        }
        if (in_array('action', $targets, true)) {
            foreach (array_keys($actions) as $name) {
                $parts[] = '[Text](action:' . $name . ')';
            }
        }
    }
    if (in_array('break', $inline, true))  { $parts[] = 'Zeilenumbruch'; }
    return '<small class="ce-field__hint">Erlaubt: ' . e(implode(' · ', $parts)) . '</small>';
};

// One scalar input (text | textarea | url | select | bool). $bfAttr is the
// data-attribute the editor JS reads ('data-bf' for top-level, 'data-bf-sub'
// inside an object list row). Sub-fields are always read as plain strings.
$ceInput = function (array $f, mixed $value, string $bfAttr) use ($ceHint): string {
    $key   = (string)($f['key'] ?? '');
    $kind  = (string)($f['kind'] ?? 'text');
    $label = (string)($f['label'] ?? $key);
    $bf    = $bfAttr . '="' . e($key) . '" data-bk="' . e($kind) . '"';

    if ($kind === 'textarea') {
        return '<label class="ce-field"><span class="ce-field__label">' . e($label) . '</span>'
            . '<textarea ' . $bf . ' rows="3" spellcheck="false">' . e((string)$value) . '</textarea>' . $ceHint($f) . '</label>';
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
        . '<input type="' . $type . '" ' . $bf . ' value="' . e((string)$value) . '" autocomplete="off">' . $ceHint($f) . '</label>';
};

// One scalar-list row (<input data-bv>, or <textarea data-bv> for `item: textarea`
// — paragraphs) — markup shared by existing rows + the row template the JS clones.
$ceScalarRow = function (string $value = '', string $itemKind = 'text'): string {
    $input = $itemKind === 'textarea'
        ? '<textarea data-bv rows="3" spellcheck="false">' . e($value) . '</textarea>'
        : '<input type="text" data-bv value="' . e($value) . '" autocomplete="off">';
    return '<div class="ce-row" data-ce-row>'
        . $input
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
$ceField = function (array $f, array $values) use ($ceInput, $ceScalarRow, $ceObjectRow, $ceHint): string {
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
            $rows .= $ceScalarRow((string)$row, (string)$item);
        }
        $tpl = $ceScalarRow('', (string)$item);
    }

    // min/max (ADR-044): the JS hides "+" at max and "×" at min; the server checks too.
    $limits = '';
    if ((int)($f['min'] ?? 0) > 0) { $limits .= ' data-min="' . (int)$f['min'] . '"'; }
    if ((int)($f['max'] ?? 0) > 0) { $limits .= ' data-max="' . (int)$f['max'] . '"'; }

    return '<div class="ce-field ce-list" data-bf="' . e($key) . '" data-bk="list" data-litem="' . ($isObject ? 'object' : 'scalar') . '"' . $limits . '>'
        . '<span class="ce-field__label">' . e((string)($f['label'] ?? $key)) . '</span>'
        . $ceHint($f)
        . '<div class="ce-list__rows" data-ce-rows>' . $rows . '</div>'
        . '<template data-ce-row-tpl>' . $tpl . '</template>'
        . '<button type="button" class="be-btn be-btn--ghost be-btn--sm" data-ce-row-add>+ Eintrag</button>'
        . '</div>';
};

// A full block card (header tools + body fields). $values = the block's data.
// With $slot (blueprint mode) the card is fixed: slot label, no move/remove tools.
$ceBlock = function (string $type, array $schema, array $values, ?array $slot = null) use ($ceField): string {
    $body = '';
    foreach ($schema as $f) {
        $body .= $ceField($f, $values);
    }
    $key    = (string)($values['key'] ?? ($slot['key'] ?? ''));
    $keyAtt = $key !== '' ? ' data-key="' . e($key) . '"' : '';

    if ($slot !== null) {
        return '<div class="ce-block ce-block--slot" data-ce-block data-type="' . e($type) . '"' . $keyAtt . '>'
            . '<header class="ce-block__head">'
            . '<span class="ce-block__slot">' . e($slot['label']) . '</span>'
            . '<span class="ce-block__type">' . e($type) . '</span>'
            . '</header>'
            . '<div class="ce-block__body">' . $body . '</div>'
            . '</div>';
    }

    return '<div class="ce-block" data-ce-block data-type="' . e($type) . '"' . $keyAtt . '>'
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
// In blueprint mode ($orphan) it is a block outside the structure: shown read-only,
// kept on save from the STORED document (the server never takes it from the post).
$ceUnknownBlock = function (array $block, bool $orphan = false): string {
    $type  = (string)($block['type'] ?? '?');
    $json  = json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tools = $orphan ? '' : '<div class="ce-block__tools">'
        . '<button type="button" class="be-icon-btn be-icon-btn--danger" data-ce-remove title="Block entfernen">&times;</button>'
        . '</div>';
    return '<div class="ce-block ce-block--unknown" data-ce-block data-type="' . e($type) . '" data-ce-raw="' . e($json) . '">'
        . '<header class="ce-block__head">'
        . '<span class="ce-block__type">' . e($type) . ($orphan ? ' (nicht in der Struktur — bleibt erhalten)' : ' (unbekannt)') . '</span>'
        . $tools . '</header>'
        . '<div class="ce-block__body"><pre class="ce-block__raw">' . e((string)$json) . '</pre></div>'
        . '</div>';
};
?>
<form data-fetch-post>
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <input type="hidden" name="entity_hash" value="<?= e($entityHash) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Neuer Inhalt' : 'Inhalt bearbeiten' ?></h2>
        <span class="be-lang-tag" title="Bearbeitungssprache"><?= e(strtoupper($content->getLanguage())) ?></span>
        <?php if (!$content->isLive()): ?>
        <span class="be-lang-tag" title="Variante — erscheint erst nach «Satz veröffentlichen» live">Variante <?= e($content->getVariant()) ?></span>
        <?php endif; ?>
        <?php
        // The live copy's last save (ADR-045); saving keeps it as a version.
        $savedAt = '';
        try {
            $savedAt = ($content->isLive() && $content->getChangedAt() !== '') ? (new DateTimeImmutable($content->getChangedAt()))->format('d.m.Y H:i') : '';
        } catch (Exception) {
        }
        ?>
        <?php if ($savedAt !== ''): ?>
        <small class="be-form__hint" title="Beim Speichern wird dieser Stand als Version gesichert">zuletzt gespeichert <?= e($savedAt) ?><?= $content->getChangedBy() !== '' ? ' von ' . e($content->getChangedBy()) : '' ?></small>
        <?php endif; ?>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php if ($validator->hasStateConflict()): ?>
                <?php foreach ($validator->getErrors() as $error): ?>
                <div><?= e($error) ?></div>
                <?php endforeach; ?>
                <button type="button" class="be-btn be-btn--ghost be-btn--sm" style="margin-top:.5rem"
                        data-fetch-get="/backend/content/content/edit?slug=<?= e(urlencode($content->getSlug())) ?>&amp;language=<?= e(urlencode($content->getLanguage())) ?>&amp;variant=<?= e(urlencode($content->getVariant())) ?>">Neu laden</button>
            <?php else: ?>
                Bitte überprüfe die markierten Eingaben.
            <?php endif; ?>
        </div>
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

            <?php if ($blueprint !== null): ?>
            <small class="be-form__hint">Feste Struktur: Abschnitte können hier nicht hinzugefügt, entfernt oder verschoben werden.</small>
            <?php endif; ?>
            <div class="ce<?= $blueprint !== null ? ' ce--locked' : '' ?>" data-ce-editor>
                <input type="hidden" name="blocks" data-ce-json>

                <div class="ce__blocks" data-ce-blocks>
                    <?php if ($blueprint !== null):
                        foreach ($blueprint->arrange($content->getBlocks(), $schemas) as $row):
                            $type = (string)($row['block']['type'] ?? '');
                            if ($row['slot'] !== null && isset($schemas[$type])):
                                echo $ceBlock($type, $schemas[$type], $row['block'], $row['slot']);
                            else:
                                echo $ceUnknownBlock($row['block'], true);
                            endif;
                        endforeach;
                    else:
                    foreach ($content->getBlocks() as $block):
                        if (!is_array($block)) { continue; }
                        $type = (string)($block['type'] ?? '');
                        if ($type !== '' && isset($schemas[$type])):
                            echo $ceBlock($type, $schemas[$type], $block);
                        else:
                            echo $ceUnknownBlock($block);
                        endif;
                    endforeach;
                    endif; ?>
                </div>

                <?php if ($blueprint === null): ?>
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
                <?php endif; ?>

                <details class="ce-preview">
                    <summary>JSON-Vorschau</summary>
                    <pre data-ce-preview></pre>
                </details>

                <?php if ($blueprint === null): ?>
                <!-- Empty per-type block templates the editor JS clones on "add". -->
                <div data-ce-templates hidden>
                    <?php foreach ($schemas as $type => $schema): ?>
                    <template data-ce-tpl="<?= e($type) ?>"><?= $ceBlock($type, $schema, []) ?></template>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
