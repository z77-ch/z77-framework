/* z77 login-user list — flat drag & drop reorder.
 *
 * Posts {entry_id, new_index} to /backend/system/login-user/move, where
 * new_index is the 0-based position among the OTHER users (the server inserts
 * the moved user into that rest-list and renumbers sortKey densely).
 */
(function () {
    'use strict';

    var body = document.getElementById('js-user-body');
    if (!body) return;

    var dragId = null, dropTarget = null, dropZone = null;

    body.querySelectorAll('.be-tree__row').forEach(function (row) {
        row.setAttribute('draggable', 'true');
    });

    body.addEventListener('dragstart', function (e) {
        var row = e.target.closest('.be-tree__row');
        if (!row) return;
        var node = row.closest('.be-tree__node');
        dragId = node.dataset.userId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragId);
        node.classList.add('be-tree__node--dragging');
    });

    body.addEventListener('dragend', function () {
        clearIndicators();
        body.querySelectorAll('.be-tree__node--dragging').forEach(function (n) {
            n.classList.remove('be-tree__node--dragging');
        });
        dragId = null; dropTarget = null; dropZone = null;
    });

    body.addEventListener('dragover', function (e) {
        if (!dragId) return;
        var row = e.target.closest('.be-tree__row');
        if (!row) return;
        var node = row.closest('.be-tree__node');
        if (!node || node.dataset.userId === dragId) return;

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        var rect = row.getBoundingClientRect();
        var zone = (e.clientY - rect.top) < rect.height / 2 ? 'before' : 'after';
        if (dropTarget !== node || dropZone !== zone) {
            clearIndicators();
            dropTarget = node;
            dropZone = zone;
            node.classList.add('be-tree__node--drop-' + zone);
        }
    });

    body.addEventListener('dragleave', function (e) {
        if (!body.contains(e.relatedTarget)) {
            clearIndicators();
            dropTarget = null;
            dropZone = null;
        }
    });

    body.addEventListener('drop', function (e) {
        if (!dragId || !dropTarget || !dropZone) return;
        e.preventDefault();

        var entryId     = parseInt(dragId, 10);
        var draggedNode = body.querySelector('.be-tree__node[data-user-id="' + entryId + '"]');
        var zone        = dropZone;
        var target      = dropTarget;

        var rest      = siblings().filter(function (n) { return n !== draggedNode; });
        var idxInRest = rest.indexOf(target);
        var newIndex  = zone === 'before' ? idxInRest : idxInRest + 1;

        clearIndicators();

        _Z77.core.fetch.post('/backend/system/login-user/move', {
            entry_id: entryId,
            new_index: newIndex
        }).then(function (env) {
            if (env && env.status === 'success') {
                target.parentNode.insertBefore(draggedNode, zone === 'before' ? target : target.nextSibling);
            }
        });
    });

    function siblings() {
        var tree = body.querySelector('.be-tree');
        return Array.from(tree.children).filter(function (n) {
            return n.classList && n.classList.contains('be-tree__node');
        });
    }

    function clearIndicators() {
        body.querySelectorAll('.be-tree__node--drop-before, .be-tree__node--drop-after').forEach(function (n) {
            n.classList.remove('be-tree__node--drop-before', 'be-tree__node--drop-after');
        });
    }
}());
