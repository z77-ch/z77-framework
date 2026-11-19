<?php
namespace Z77\Module\Backend\Dashboard;

use Z77\Core\DI;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\ProjectHealth\CheckCategory;
use Z77\Shared\ProjectHealth\ProjectCheck;
use Z77\Shared\ProjectHealth\ProjectChecks;

/**
 * THE bridge between the project checks (`projectChecks`, {@see ProjectChecks}) and the backend
 * dashboard (ADR-052) — project-health bauplan D1: ONE `DashboardContributor`, registered under
 * `dashboard` in `backendConfig`, that asks every registered {@see ProjectCheck} and turns the
 * open ones into dashboard lines.
 *
 * **First setup («Ersteinrichtung», owner 2026-11-19): not everything at once.** The setup checks
 * are asked in their {@see ProjectCheck::order()} and only the first N OPEN ones become lines —
 * N = `dashboardSetupSteps` in `backendConfig` (optional key, default {@see DEFAULT_SETUP_STEPS}).
 * A step done disappears and the next one moves up. A step behind the limit is never asked
 * (cheap: a running installation asks only until it has found N or run out).
 *
 * **Access (bauplan D7):** a check whose target the user may not reach is skipped BEFORE it is
 * asked and does not take a slot — an editor is not shown «Kontenplan übernehmen», and the
 * limit counts only steps the user can act on. The board filters by `canReach()` again
 * (harmless; one rule for every contributor).
 *
 * **Health checks** (category Health) have no order and no limit: every open one is a line.
 *
 * **Failure isolation** (ADR-052 §7): a check that throws is logged and becomes ONE line
 * «Projekt-Prüfung «{id}» nicht verfügbar» (no target, so no link); a setup check's line takes
 * its slot. The other checks stand.
 */
final class ProjectHealthDashboard implements DashboardContributor
{
    /** How many open setup steps the dashboard shows when `backendConfig` sets none (owner 2026-11-19: «2–3»). */
    public const DEFAULT_SETUP_STEPS = 3;

    /** Optional `backendConfig` key: how many open setup steps are shown (int ≥ 1). */
    public const CONFIG_SETUP_STEPS = 'dashboardSetupSteps';

    public function entries(AuthUser $user): iterable
    {
        $auth    = DI::getAuthService();
        $modules = DI::getModuleManager();

        return self::entriesFrom(
            ProjectChecks::fromModules($modules),
            fn(string $module, string $group, string $controller, string $action): bool
                => $auth->canReach($user, $module, $group, $controller, $action),
            self::setupSteps($modules->getModuleConfig('backend')?->get(self::CONFIG_SETUP_STEPS))
        );
    }

    /** The configured number of setup steps: null → the default; anything but an int ≥ 1 is refused. */
    public static function setupSteps(mixed $configured): int
    {
        if ($configured === null) {
            return self::DEFAULT_SETUP_STEPS;
        }
        if (!is_int($configured) || $configured < 1) {
            throw new \RuntimeException('❌ backendConfig `' . self::CONFIG_SETUP_STEPS . '` must be an int ≥ 1, got ' . var_export($configured, true) . '.');
        }

        return $configured;
    }

    /**
     * The dashboard lines of $registry for one user — pure over the registry and the access
     * callable, so the harness checks the rule without DI. Setup lines first (in setup order),
     * then health lines (registration order).
     *
     * @param callable(string, string, string, string): bool $reachable (module, group, controller, action) — `canReach()` for one user
     * @return list<DashboardEntry>
     */
    public static function entriesFrom(ProjectChecks $registry, callable $reachable, int $setupSteps): array
    {
        $setup  = [];
        $health = [];
        foreach ($registry->checks() as $position => $check) {
            try {
                $category = $check->category();
                $order    = $category === CheckCategory::Setup ? $check->order() : 0;
                $target   = $check->target();
            } catch (\Throwable $e) {
                $health[] = self::unavailable($check, $e);
                continue;
            }
            if ($category === CheckCategory::Setup) {
                $setup[] = ['check' => $check, 'order' => $order, 'target' => $target, 'position' => $position];
            } else {
                $health[] = ['check' => $check, 'target' => $target];
            }
        }
        usort($setup, static fn(array $a, array $b) => [$a['order'], $a['position']] <=> [$b['order'], $b['position']]);

        $lines = [];
        foreach ($setup as $step) {
            if (count($lines) >= $setupSteps) {
                break;
            }
            $line = self::ask($step['check'], $step['target'], $reachable);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        foreach ($health as $item) {
            $line = $item instanceof DashboardEntry ? $item : self::ask($item['check'], $item['target'], $reachable);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The line of one check, or null when the user may not reach its target or nothing is open.
     *
     * @param list<string> $target
     */
    private static function ask(ProjectCheck $check, array $target, callable $reachable): ?DashboardEntry
    {
        try {
            if (!array_is_list($target) || count($target) !== 4) {
                throw new \InvalidArgumentException('target() must be [module, group, controller, action], got ' . json_encode($target));
            }
            if (!$reachable(...$target)) {
                return null;   // not reachable: not asked, no slot taken
            }
            if (!$check->isOpen()) {
                return null;
            }

            return DashboardEntry::pending($check->severity(), $check->label(), $target);
        } catch (\Throwable $e) {
            return self::unavailable($check, $e);
        }
    }

    private static function unavailable(ProjectCheck $check, \Throwable $e): DashboardEntry
    {
        try {
            $id = $check->id();
        } catch (\Throwable) {
            $id = $check::class;
        }
        error_log("dashboard: project check {$id} (" . $check::class . ") failed: " . $e);

        return DashboardEntry::unavailable("Projekt-Prüfung «{$id}» nicht verfügbar");
    }
}
