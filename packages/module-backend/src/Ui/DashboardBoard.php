<?php
namespace Z77\Module\Backend\Ui;

use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Money\AmountFormat;

/**
 * The backend dashboard as the current user may see it (ADR-052): one TILE
 * per visible section of the backend menu — its links are the section's
 * visible pages —, the contributors' entries placed on the tile whose section
 * holds their target page, and the PENDING list of all of them, urgent first.
 *
 * Two halves, like {@see BackendMenu}: {@see tilesFrom()} reads the menu (the
 * one navigation walk — no second «which modules exist» list), {@see build()}
 * is pure — tiles, entries and the access check come in as data and a
 * callable, so `tests/backend-dashboard.php` checks it without DI.
 *
 * Placement: an entry's target route `[module, group, controller, action]`
 * is looked up among the routes of every tile — an exact match first, then
 * the same controller (a create entry's `add` is not in the menu, its list
 * is). An entry no tile holds still appears in the pending list (a create
 * entry without a tile is dropped: it has no head to stand in).
 */
final class DashboardBoard
{
    /**
     * The tiles of the visible sections, in menu order.
     *
     * @return list<array{name: string, url: string, links: list<array{name: string, href: string}>, routes: list<array{route: list<string>, name: string}>}>
     */
    public static function tilesFrom(BackendMenu $menu): array
    {
        $tiles = [];
        foreach ($menu->sections() as $item) {
            $links  = [];
            $routes = [];
            self::walk($menu, $item['section'], $links, $routes);
            $tiles[] = [
                'name'   => $item['section']->getName(),
                'url'    => $item['url'],
                'links'  => array_values($links),
                'routes' => $routes,
            ];
        }

        return $tiles;
    }

    /**
     * @param array<string, array{name: string, href: string}>     $links  href => link (deduplicated)
     * @param list<array{route: list<string>, name: string}>       $routes
     */
    private static function walk(BackendMenu $menu, Navigation $entry, array &$links, array &$routes, int $depth = 0): void
    {
        if ($depth > 20) {   // defensive, like BackendMenu::visibleIn()
            return;
        }
        foreach ($menu->children($entry) as $child) {
            if ($child->getRef() === null && $child->getController() !== '') {
                $routes[] = ['route' => [$child->getModule(), $child->getGroup(), $child->getController(), $child->getAction()], 'name' => $child->getName()];
            }
            $grandChildren = $menu->children($child);
            if ($grandChildren === []) {
                $href = $menu->href($child);
                if ($href !== '') {
                    $links[preg_replace('~[?&]via=\d+$~', '', $href)] ??= ['name' => $child->getName(), 'href' => $href];
                }
                continue;
            }
            self::walk($menu, $child, $links, $routes, $depth + 1);
        }
    }

    /**
     * @param list<array{name: string, url: string, links: list<array{name: string, href: string}>, routes: list<array{route: list<string>, name: string}>}> $tiles
     * @param list<DashboardEntry> $entries
     * @param callable(string, string, string, string): bool $reachable (module, group, controller, action) — AuthService::canReach() for one user
     * @return array{
     *     tiles: list<array{name: string, url: string, links: list<array{name: string, href: string}>, creates: list<array{label: string, href: string, opens: string}>, badges: list<array>}>,
     *     pending: list<array{severity: string, severityLabel: string, label: string, where: string, figure: string, href: string}>
     * }
     */
    public static function build(array $tiles, array $entries, callable $reachable): array
    {
        $out = [];
        foreach ($tiles as $tile) {
            $out[] = ['name' => $tile['name'], 'url' => $tile['url'], 'links' => $tile['links'], 'creates' => [], 'badges' => []];
        }

        $pending = [];
        foreach ($entries as $position => $entry) {
            if ($entry->hasTarget() && !$reachable(...$entry->route)) {
                continue;   // not reachable: not shown, not even as a number
            }
            [$index, $pageName] = $entry->hasTarget() ? self::place($tiles, $entry->route) : [null, ''];

            if (!$entry->isPending()) {
                if ($index !== null) {
                    $out[$index]['creates'][] = ['label' => $entry->label, 'href' => $entry->href(), 'opens' => $entry->opens];
                }
                continue;
            }

            $row = [
                'severity'      => $entry->severity->value,
                'severityLabel' => $entry->severity->label(),
                'label'         => $entry->label,
                'where'         => $index === null ? '' : $tiles[$index]['name'] . ($pageName !== '' && $pageName !== $tiles[$index]['name'] ? ' › ' . $pageName : ''),
                'figure'        => self::figure($entry),
                'href'          => $entry->href(),
                'rank'          => $entry->severity->rank(),
                'position'      => $position,
            ];
            $pending[] = $row;
            if ($index !== null) {
                $out[$index]['badges'][] = $row;
            }
        }

        $byWeight = static fn(array $a, array $b) => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']];
        usort($pending, $byWeight);
        foreach ($out as &$tile) {
            usort($tile['badges'], $byWeight);
        }
        unset($tile);

        return ['tiles' => $out, 'pending' => $pending];
    }

    /**
     * The tile holding $route and the name of the menu page it matched: an
     * exact route first, then the same module/group/controller.
     *
     * @return array{0: ?int, 1: string}
     */
    private static function place(array $tiles, array $route): array
    {
        foreach ([4, 3] as $length) {
            $want = array_slice($route, 0, $length);
            foreach ($tiles as $index => $tile) {
                foreach ($tile['routes'] as $candidate) {
                    if (array_slice($candidate['route'], 0, $length) === $want) {
                        return [$index, $candidate['name']];
                    }
                }
            }
        }

        return [null, ''];
    }

    /** «4 · CHF 3'870.00», «4», «CHF 3'870.00» or ''. */
    private static function figure(DashboardEntry $entry): string
    {
        $parts = [];
        if ($entry->count !== null) {
            $parts[] = (string) $entry->count;
        }
        if ($entry->amount !== null) {
            $parts[] = $entry->amount->currency . ' ' . AmountFormat::of($entry->amount);
        }

        return implode(' · ', $parts);
    }
}
