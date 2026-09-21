/* z77 content block editor (modal)
 *
 * Loaded lazily via the `load-script` command from ContentController::edit().
 * Re-invoked on every popup open against the freshly mounted modal body.
 *
 * Contract (markup built server-side from each BlockRenderer::schema()):
 *   [data-ce-editor]                 editor root
 *   [data-ce-blocks]                 live block list (DOM order = block order)
 *   [data-ce-block] [data-type]      one block card; [data-key] = its slot key (ADR-044), written back
 *   [data-bf] [data-bk]              a top-level field (kind in data-bk)
 *   [data-bk="list"] [data-litem]    a list field (scalar | object items); [data-min]/[data-max]
 *                                    hide "+" at max and "×" at min (the server checks too)
 *     [data-ce-rows] > [data-ce-row] live rows; [data-bv] scalar / [data-bf-sub] object sub-field
 *     [data-ce-row-tpl]              <template> for a fresh row
 *   [data-ce-raw]                    unknown-type block → emit its JSON verbatim
 *   [data-ce-tpl="<type>"]           <template> for a fresh block of that type
 *   input[name="blocks"][data-ce-json]   hidden submit field (kept in sync)
 *
 * Block field inputs carry NO `name` — the shared form collector ignores them;
 * only the hidden `blocks` field is posted, as the same JSON the server already
 * validates (ContentValidator). Single source: the visual editor.
 *
 * Blueprint mode (.ce--locked) needs no JS of its own: the server renders no
 * move/remove/add controls, and it re-imposes the slots on save anyway.
 */
(function () {
    'use strict';

    _Z77.scriptInit['content-editor'] = function (scope) {
        var editor = scope.querySelector('[data-ce-editor]');
        if (!editor) return;

        var blocks  = editor.querySelector('[data-ce-blocks]');
        var json    = editor.querySelector('[data-ce-json]');
        var preview = editor.querySelector('[data-ce-preview]');
        var empty   = editor.querySelector('[data-ce-empty]');
        var addType = editor.querySelector('[data-ce-add-type]');

        function readList(listEl) {
            var object = listEl.getAttribute('data-litem') === 'object';
            var rowsEl = listEl.querySelector('[data-ce-rows]');
            var out = [];
            if (!rowsEl) return out;
            Array.prototype.forEach.call(rowsEl.children, function (row) {
                if (!row.hasAttribute('data-ce-row')) return;
                if (object) {
                    var obj = {}, any = false;
                    row.querySelectorAll('[data-bf-sub]').forEach(function (s) {
                        obj[s.getAttribute('data-bf-sub')] = s.value;
                        if (s.value !== '') any = true;
                    });
                    if (any) out.push(obj);
                } else {
                    var inp = row.querySelector('[data-bv]');
                    var v = inp ? inp.value : '';
                    if (v !== '') out.push(v);
                }
            });
            return out;
        }

        function readBlock(blockEl) {
            var raw = blockEl.getAttribute('data-ce-raw');
            if (raw !== null) {
                try { return JSON.parse(raw); } catch (e) { return null; }
            }
            var block = { type: blockEl.getAttribute('data-type') };
            var key = blockEl.getAttribute('data-key');
            if (key) block.key = key;
            blockEl.querySelectorAll('[data-bf]').forEach(function (f) {
                var key  = f.getAttribute('data-bf');
                var kind = f.getAttribute('data-bk');
                if (kind === 'list') {
                    block[key] = readList(f);
                } else if (kind === 'bool') {
                    block[key] = !!f.checked;
                } else {
                    block[key] = f.value;
                }
            });
            return block;
        }

        function sync() {
            var out = [];
            Array.prototype.forEach.call(blocks.children, function (el) {
                if (!el.hasAttribute('data-ce-block')) return;
                var b = readBlock(el);
                if (b) out.push(b);
            });
            json.value = JSON.stringify(out);
            if (preview) preview.textContent = JSON.stringify(out, null, 2);
        }

        // min/max per list: a row count outside the bounds cannot be reached by clicking.
        function updateLimits(listEl) {
            var rows = listEl.querySelector('[data-ce-rows]');
            if (!rows) return;
            var count = rows.querySelectorAll(':scope > [data-ce-row]').length;
            var min = parseInt(listEl.getAttribute('data-min') || '0', 10);
            var max = parseInt(listEl.getAttribute('data-max') || '0', 10);
            var add = listEl.querySelector(':scope > [data-ce-row-add]');
            if (add) add.hidden = max > 0 && count >= max;
            rows.querySelectorAll(':scope > [data-ce-row] > [data-ce-row-remove]').forEach(function (btn) {
                btn.hidden = min > 0 && count <= min;
            });
        }

        function updateAllLimits() {
            editor.querySelectorAll('.ce-list').forEach(updateLimits);
        }

        function updateEmpty() {
            if (!empty) return;
            var has = blocks.querySelector('[data-ce-block]') !== null;
            empty.hidden = has;
        }

        function cloneTemplate(tpl) {
            // <template>.content is inert; first element child is the card/row.
            return tpl.content.firstElementChild.cloneNode(true);
        }

        function addBlock() {
            if (!addType || !addType.value) return;
            var tpl = editor.querySelector('[data-ce-tpl="' + CSS.escape(addType.value) + '"]');
            if (!tpl) return;
            blocks.appendChild(cloneTemplate(tpl));
            updateEmpty();
            updateAllLimits();
            sync();
        }

        function addRow(listEl) {
            var tpl  = listEl.querySelector('[data-ce-row-tpl]');
            var rows = listEl.querySelector('[data-ce-rows]');
            if (!tpl || !rows) return;
            rows.appendChild(cloneTemplate(tpl));
            updateLimits(listEl);
            sync();
        }

        editor.addEventListener('click', function (e) {
            var t = e.target;
            if (t.closest('[data-ce-add]'))        { addBlock(); return; }
            if (t.closest('[data-ce-row-add]'))     { addRow(t.closest('.ce-list')); return; }

            var block = t.closest('[data-ce-block]');

            if (t.closest('[data-ce-remove]')) {
                if (block) { block.remove(); updateEmpty(); sync(); }
                return;
            }
            if (t.closest('[data-ce-row-remove]')) {
                var row = t.closest('[data-ce-row]');
                var list = t.closest('.ce-list');
                if (row) { row.remove(); if (list) updateLimits(list); sync(); }
                return;
            }
            if (t.closest('[data-ce-up]') && block) {
                var prev = block.previousElementSibling;
                if (prev) { blocks.insertBefore(block, prev); sync(); }
                return;
            }
            if (t.closest('[data-ce-down]') && block) {
                var next = block.nextElementSibling;
                if (next) { blocks.insertBefore(next, block); sync(); }
                return;
            }
        });

        // Any field edit re-serialises (ignore the add-type selector).
        editor.addEventListener('input', function (e) {
            if (e.target === addType) return;
            sync();
        });
        editor.addEventListener('change', function (e) {
            if (e.target === addType) return;
            sync();
        });

        updateEmpty();
        updateAllLimits();
        sync();
    };
}());
