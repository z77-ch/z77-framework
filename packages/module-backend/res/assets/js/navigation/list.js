(function () {
    // ── Print ─────────────────────────────────────────────────────────────────
    document.getElementById('js-nav-print').addEventListener('click', function () { window.print(); });

    // ── Filter ────────────────────────────────────────────────────────────────
    document.getElementById('js-nav-filter').addEventListener('input', function () {
        var term = this.value.trim().toLowerCase();
        document.querySelectorAll('.be-tree__node').forEach(function (node) {
            node.style.display = !term || node.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    });

    // ── Group tabs ────────────────────────────────────────────────────────────
    document.querySelectorAll('.be-tabs__tab[data-group]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.be-tabs__tab[data-group]').forEach(function (t) {
                t.classList.remove('be-tabs__tab--active');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.add('be-tabs__tab--active');
            tab.setAttribute('aria-selected', 'true');

            var group = tab.dataset.group;
            document.querySelectorAll('.be-list__section').forEach(function (section) {
                section.style.display = group === '*' || section.dataset.group === group ? '' : 'none';
            });
        });
    });

    // ── Tree toggle ───────────────────────────────────────────────────────────
    document.getElementById('js-nav-body').addEventListener('click', function (e) {
        var toggle = e.target.closest('.be-tree__toggle');
        if (toggle) {
            var node = toggle.closest('.be-tree__node');
            if (node) node.classList.toggle('is-open');
        }
    });

    // ── Drag & Drop ───────────────────────────────────────────────────────────
    initDragDrop();

    function initDragDrop() {
        var body = document.getElementById('js-nav-body');
        var dragId = null;
        var dropTarget = null;
        var dropZone = null; // 'before' | 'into' | 'after'

        // Make all node rows draggable
        body.querySelectorAll('.be-tree__row').forEach(function (row) {
            row.setAttribute('draggable', 'true');
        });

        body.addEventListener('dragstart', function (e) {
            var row = e.target.closest('.be-tree__row');
            if (!row) return;
            var node = row.closest('.be-tree__node');
            dragId = node.dataset.navId;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragId);
            node.classList.add('be-tree__node--dragging');
        });

        body.addEventListener('dragend', function () {
            clearDropIndicators();
            body.querySelectorAll('.be-tree__node--dragging').forEach(function (n) {
                n.classList.remove('be-tree__node--dragging');
            });
            dragId = null;
            dropTarget = null;
            dropZone = null;
        });

        body.addEventListener('dragover', function (e) {
            if (!dragId) return;
            var row = e.target.closest('.be-tree__row');
            if (!row) return;
            var node = row.closest('.be-tree__node');
            if (!node || node.dataset.navId === dragId) return;
            // disallow into-drop on ref nodes
            var isRef = node.classList.contains('be-tree__node--ref');

            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            var rect = row.getBoundingClientRect();
            var y = e.clientY - rect.top;
            var third = rect.height / 3;
            var zone;
            if (y < third) zone = 'before';
            else if (y > 2 * third || isRef) zone = (y > rect.height / 2) ? 'after' : 'before';
            else zone = 'into';

            if (dropTarget !== node || dropZone !== zone) {
                clearDropIndicators();
                dropTarget = node;
                dropZone   = zone;
                node.classList.add('be-tree__node--drop-' + zone);
            }
        });

        body.addEventListener('dragleave', function (e) {
            // only clear when leaving the whole body
            if (!body.contains(e.relatedTarget)) {
                clearDropIndicators();
                dropTarget = null;
                dropZone = null;
            }
        });

        body.addEventListener('drop', function (e) {
            if (!dragId || !dropTarget || !dropZone) return;
            e.preventDefault();

            var entryId = parseInt(dragId, 10);
            var targetId = parseInt(dropTarget.dataset.navId, 10);
            var newParentId, newIndex;

            if (dropZone === 'into') {
                newParentId = targetId;
                // append as last child
                var childrenContainer = dropTarget.querySelector(':scope > .be-tree__children');
                newIndex = childrenContainer ? childrenContainer.children.length : 0;
            } else {
                var parentNode = parentNodeOf(dropTarget);
                newParentId = parentNode ? parseInt(parentNode.dataset.navId, 10) : 0;
                var siblings = siblingsOf(dropTarget);
                var targetIndex = siblings.indexOf(dropTarget);
                newIndex = dropZone === 'before' ? targetIndex : targetIndex + 1;
                // when moving within same parent and original position is before target, shift index back by one
                var draggedNode = body.querySelector('.be-tree__node[data-nav-id="' + entryId + '"]');
                if (draggedNode && parentNodeOf(draggedNode) === parentNode) {
                    var origIndex = siblings.indexOf(draggedNode);
                    if (origIndex >= 0 && origIndex < targetIndex) newIndex--;
                }
            }

            clearDropIndicators();

            _Z77.core.fetch.post('/backend/content/navigation/move', {
                entry_id: entryId,
                new_parent_id: newParentId,
                new_index: newIndex
            }).then(function (env) {
                if (env && env.status === 'success') {
                    moveDom(entryId, newParentId, newIndex);
                }
            });
        });

        function clearDropIndicators() {
            body.querySelectorAll('.be-tree__node--drop-before, .be-tree__node--drop-after, .be-tree__node--drop-into').forEach(function (n) {
                n.classList.remove('be-tree__node--drop-before', 'be-tree__node--drop-after', 'be-tree__node--drop-into');
            });
        }

        function parentNodeOf(nodeEl) {
            var childrenContainer = nodeEl.parentElement
                ? nodeEl.parentElement.closest('.be-tree__children')
                : null;
            return childrenContainer ? childrenContainer.closest('.be-tree__node') : null;
        }

        function siblingsOf(nodeEl) {
            if (!nodeEl.parentElement) return [];
            return Array.from(nodeEl.parentElement.children).filter(function (s) {
                return s.classList && s.classList.contains('be-tree__node');
            });
        }

        function moveDom(entryId, newParentId, newIndex) {
            var node = body.querySelector('.be-tree__node[data-nav-id="' + entryId + '"]');
            if (!node) return;

            var targetContainer;
            if (newParentId === 0) {
                // top-level: keep entry in its current section (server preserves group slug)
                targetContainer = node.closest('.be-tree');
                if (!targetContainer) return;
            } else {
                var parentNode = body.querySelector('.be-tree__node[data-nav-id="' + newParentId + '"]');
                if (!parentNode) return;
                targetContainer = parentNode.querySelector(':scope > .be-tree__children');
                if (!targetContainer) {
                    targetContainer = document.createElement('div');
                    targetContainer.className = 'be-tree__children';
                    parentNode.appendChild(targetContainer);
                    parentNode.classList.add('be-tree__node--has-children', 'is-open');
                }
            }

            var siblings = Array.from(targetContainer.children).filter(function (s) {
                return s.classList && s.classList.contains('be-tree__node') && s !== node;
            });
            var anchor = siblings[newIndex] || null;
            targetContainer.insertBefore(node, anchor);

            // update --node-depth based on actual nesting
            updateDepth(node, computeDepth(node));
        }

        function computeDepth(nodeEl) {
            var depth = 0;
            var cur = nodeEl.parentElement;
            while (cur && !cur.classList.contains('be-tree')) {
                if (cur.classList && cur.classList.contains('be-tree__children')) depth++;
                cur = cur.parentElement;
            }
            return depth;
        }

        function updateDepth(nodeEl, depth) {
            nodeEl.style.setProperty('--node-depth', depth);
            var childContainer = nodeEl.querySelector(':scope > .be-tree__children');
            if (childContainer) {
                Array.from(childContainer.children).forEach(function (c) {
                    if (c.classList && c.classList.contains('be-tree__node')) {
                        updateDepth(c, depth + 1);
                    }
                });
            }
        }
    }
}());
