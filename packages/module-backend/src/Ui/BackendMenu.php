<?php
namespace Z77\Module\Backend\Ui;

use Z77\Core\DI,
    Z77\Core\Services\NavigationService,
    Z77\Shared\Entities\Navigation
;

/**
 * The backend menu as the current user may see it (ADR-045 §2).
 *
 * The rule: an entry is shown only if the user may call the page it leads to,
 * decided by {@see \Z77\Shared\Services\AuthService::canReach()} — the same
 * resolution AccessGuard applies on dispatch. There is no second «who sees
 * what» list: lowering a controller's role in the module config makes it
 * visible, raising it hides it.
 *
 *   - leaf with a target (module/group/controller/action) → visible if reachable
 *   - ref entry → visible if its target entry is reachable
 *   - entry with children → visible if at least one child is visible (a
 *     section or opener with nothing inside disappears; its own target is not
 *     asked — the subnav never links an opener)
 *   - leaf without target and without ref → hidden (it leads nowhere)
 *
 * Built by BackendAbstractController::html() for a logged-in user and handed to
 * the shell templates (topbar, subnav, crumb) as `backendMenu`; the templates
 * render what it returns and make no access decision of their own
 * (HEADER-AUTH-001). Visibility is not security: AccessGuard still refuses a
 * route the menu would have hidden.
 */
final class BackendMenu
{
    /** @var array<int, bool> entry id => visible */
    private array $memo = [];

    /**
     * @param \Closure(string, string, string, string): bool $reachable
     *        (module, group, controller, action) as a navigation entry stores them
     */
    public function __construct(
        private NavigationService $nav,
        private string $slot,
        private \Closure $reachable
    ) {}

    public static function forCurrentUser(string $slot): self
    {
        $auth = DI::getAuthService();
        $user = $auth->getCurrentUser();

        return new self(
            DI::getNavigationService(),
            $slot,
            fn(string $module, string $group, string $controller, string $action): bool
                => $auth->canReach($user, $module, $group, $controller, $action)
        );
    }

    /**
     * Visible top-level sections of the slot, each with the URL of the first
     * page in it the user may open ('' = none, rendered inert).
     *
     * @return list<array{section: Navigation, url: string, active: bool}>
     */
    public function sections(): array
    {
        $out = [];
        foreach ($this->nav->iterateSections($this->slot) as $item) {
            if (!$this->isVisible($item['section'])) {
                continue;
            }
            $first = $this->nav->resolveFirstNavigable($item['section'], fn(Navigation $e) => $this->allows($e));
            $out[] = [
                'section' => $item['section'],
                'url'     => $first !== null ? $this->href($first) : '',
                'active'  => $item['active'],
            ];
        }
        return $out;
    }

    /** The section holding the UI cursor, if the user may see it. */
    public function activeSection(): ?Navigation
    {
        $section = $this->nav->getActiveSectionBySlot($this->slot);

        return ($section !== null && $this->isVisible($section)) ? $section : null;
    }

    /** @return Navigation[] the visible children, in menu order */
    public function children(Navigation $entry): array
    {
        if ($entry->getRef() !== null) {
            return [];
        }
        return array_values(array_filter(
            $this->nav->getChildren($entry),
            fn(Navigation $child) => $this->isVisible($child)
        ));
    }

    /**
     * Href of an entry: ref → target URL + `?via=<refId>`, otherwise its own URL.
     * '' when the entry leads nowhere or the user may not open it.
     */
    public function href(Navigation $entry): string
    {
        if (!$this->allows($entry)) {
            return '';
        }
        if ($entry->getRef() !== null) {
            $target = $this->nav->findById($entry->getRef());
            return $target !== null ? $this->nav->urlForVia($target, (int)$entry->getId()) : '';
        }
        return $this->nav->urlFor($entry);
    }

    public function isActive(Navigation $entry): bool
    {
        return $this->nav->isActive($entry);
    }

    /** The environment switcher, each area linked to its first page the user may open. */
    public function viewAreas(): array
    {
        return $this->nav->getViewAreas(fn(Navigation $e) => $this->allows($e));
    }

    public function currentViewAreaName(): ?string
    {
        return $this->nav->getCurrentViewAreaName();
    }

    public function isVisible(Navigation $entry): bool
    {
        return self::visibleIn(
            $entry,
            fn(Navigation $e) => $this->nav->getChildren($e),
            fn(Navigation $e) => $this->allows($e),
            $this->memo
        );
    }

    /** May the user open the page this entry leads to? (ref → its target) */
    public function allows(Navigation $entry): bool
    {
        return self::allowsIn($entry, fn(int $id) => $this->nav->findById($id), $this->reachable);
    }

    /**
     * The access half of the rule, pure like {@see visibleIn()}: a ref asks for
     * its target entry, an entry without a target leads nowhere. Lives in
     * {@see NavigationService::entryAllowedIn()} — the frontend admin overlay
     * asks the same question and cannot depend on this module.
     *
     * @param callable(int): ?Navigation                    $findById
     * @param callable(string, string, string, string): bool $reachable
     */
    public static function allowsIn(Navigation $entry, callable $findById, callable $reachable): bool
    {
        return NavigationService::entryAllowedIn($entry, $findById, $reachable);
    }

    /**
     * The visibility rule over a tree, pure (the children lookup and the access
     * check come in as callables) so it can be tested without a navigation store
     * (tests/backend-access.php). See the class comment for the rule.
     *
     * @param callable(Navigation): Navigation[] $children
     * @param callable(Navigation): bool         $allows
     * @param array<int, bool>                   $memo  entry id => visible
     */
    public static function visibleIn(Navigation $entry, callable $children, callable $allows, array &$memo = [], int $depth = 0): bool
    {
        $id = $entry->getId();
        if ($id !== null && isset($memo[$id])) {
            return $memo[$id];
        }
        if ($depth > 20) { // defensive guard against hand-edited parent cycles
            return false;
        }

        $kids = $entry->getRef() === null ? $children($entry) : [];
        if ($kids === []) {
            $visible = $allows($entry);
        } else {
            $visible = false;
            foreach ($kids as $kid) {
                if (self::visibleIn($kid, $children, $allows, $memo, $depth + 1)) {
                    $visible = true;
                    break;
                }
            }
        }

        if ($id !== null) {
            $memo[$id] = $visible;
        }
        return $visible;
    }
}
