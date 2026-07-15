<?php
namespace Z77\Module\Backend\Ui\Controllers;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Tree\TreeNode,
    Z77\Shared\Tree\TreeService
;

/**
 * Base for backend controllers that manage a self-referential {@see TreeNode}
 * forest (e.g. navigation entries; the reuse seam for future tree-entity
 * controllers). Provides the generic,
 * mechanical tree move once — the part that is identical for every tree entity:
 * resolve the node set, cycle-guard, splice via {@see TreeService::reorderInto},
 * renumber the old sibling group, persist.
 *
 * Entity-specific *policy* — which parent/scope a node may take, cross-scope
 * guards, what a node inherits on reparent — stays in {@see applyMovePolicy()}.
 * The split mirrors the mechanics-vs-policy line of `TreeService` itself
 * (see tree.md / ADR-009).
 */
abstract class AbstractTreeEntityController extends BackendAbstractController
{
    /** The repository for the tree entity (its `findAll()` is the working set). */
    abstract protected function treeRepo(): RepositoryInterface;

    /** The TreeService configured for this entity (its `scopeOf` partitions roots). */
    abstract protected function treeService(): TreeService;

    /**
     * Entity policy for a move. Receives the moved node, the resolved target
     * parent (null = move to top-level), and the full id→node index. MUST set
     * the node's target `parentId` and any scope field BEFORE returning. Returns
     * an error message to abort the move, or null when the move is allowed.
     *
     * @param array<int, TreeNode> $index
     */
    abstract protected function applyMovePolicy(TreeNode $node, ?TreeNode $newParent, array $index): ?string;

    // em() + fetchError() are shared backend helpers — see BackendAbstractController.

    /**
     * Atomic tree mutation (reorder + reparent). Payload: entry_id,
     * new_parent_id (0 = top-level), new_index (0-based position among target
     * siblings). Mechanics here; entity rules in {@see applyMovePolicy()}.
     */
    #[Fetch, HttpMethod('POST')]
    protected function moveAction(): FetchResponse
    {
        $body        = DI::getRequest()->getJsonBody();
        $entryId     = (int)($body['entry_id'] ?? 0);
        $newParentId = (int)($body['new_parent_id'] ?? 0);
        $newIndex    = max(0, (int)($body['new_index'] ?? 0));

        if ($entryId <= 0) {
            return $this->fetchError('Missing entry_id');
        }

        $tree  = $this->treeService();
        $index = $tree->index($this->treeRepo()->findAll());

        $node = $index[$entryId] ?? null;
        if ($node === null) {
            return $this->fetchError('Eintrag nicht gefunden.');
        }

        $newParent = $newParentId > 0 ? ($index[$newParentId] ?? null) : null;
        if ($newParentId > 0 && $newParent === null) {
            return $this->fetchError('Ziel-Elternknoten nicht gefunden.');
        }
        if ($newParent !== null && $tree->isDescendantOf($index, $newParent, $node)) {
            return $this->fetchError('Knoten kann nicht in sein eigenes Kind verschoben werden.');
        }

        // Capture the OLD sibling group (parent + scope) before policy mutates the
        // node — only the caller knows the old scope afterwards.
        $oldParentId = $node->getParentId();
        $oldScope    = $tree->scopeOf($node);

        // Entity policy: sets target parentId + scope on $node, or aborts.
        $error = $this->applyMovePolicy($node, $newParent, $index);
        if ($error !== null) {
            return $this->fetchError($error);
        }

        $em = $this->em();

        // Splice into the (now correctly set) target group + renumber it densely.
        foreach ($tree->reorderInto($index, $node, $newIndex) as $member) {
            $em->persist($member);
        }

        // Renumber the old group too when the node actually left it.
        if ($oldParentId !== $node->getParentId() || $oldScope !== $tree->scopeOf($node)) {
            foreach ($tree->renumber($tree->siblingGroup($index, $oldParentId, $oldScope, $entryId)) as $member) {
                $em->persist($member);
            }
        }

        $em->flush();

        return $this->fetch()->setStatus('success');
    }
}
