<?php

/**
 * Alert-service harness (CLI) — the edge-triggered outage machine behind
 * operator alerting (alarm handoff 2026-09-02).
 *
 * What is load-bearing here:
 *
 *   - EDGES, not occurrences: ok→failing sends ONE Outage, every further
 *     failure inside the window is silent, the window running out sends ONE
 *     Escalation (never a second), failing→ok sends ONE Recovery;
 *   - success on an ok source sends nothing and only refreshes last_success —
 *     the age anchor later messages report («served stand is … old»);
 *   - a NEW incident after recovery alerts again (escalation flag reset);
 *   - state survives across service instances (file under the state dir) —
 *     the release-switch guarantee reduced to what a harness can prove;
 *   - a throwing channel is contained: the caller's run survives, the other
 *     channels still receive the message;
 *   - the message carries what the handoff demands: code, failing-since,
 *     last-success, and the derived ages.
 *
 * Run: php tests/alert-service.php
 * Uses a throwaway state directory in the system temp; removed on success.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'      => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => __DIR__ . '/../packages/kernel/persistence/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Shared\Alert\AlertChannelInterface;
use Z77\Shared\Alert\AlertKind;
use Z77\Shared\Alert\AlertMessage;
use Z77\Shared\Alert\AlertService;

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL') . "  $label\n";
    if (!$ok) {
        $failures++;
    }
};

final class RecordingChannel implements AlertChannelInterface
{
    /** @var list<AlertMessage> */
    public array $sent = [];

    public function send(AlertMessage $message): void
    {
        $this->sent[] = $message;
    }
}

final class BrokenChannel implements AlertChannelInterface
{
    public function send(AlertMessage $message): void
    {
        throw new RuntimeException('provider down');
    }
}

$work    = sys_get_temp_dir() . '/z77-alert-' . getmypid();
$channel = new RecordingChannel();
$svc     = new AlertService($work, [$channel], escalationSeconds: 4 * 3600);

$t = 1_000_000; // fixed clock

// ── A. the incident lifecycle ────────────────────────────────────────────────

$check('A1 healthy success → silent', $svc->success('api:axo3', [], $t) === null);
$check('A2 first failure → Outage', $svc->failure('api:axo3', 'network', [], $t + 600) === AlertKind::Outage);
$check('A3 second failure inside window → silent', $svc->failure('api:axo3', 'network', [], $t + 1200) === null);
$check('A4 third failure inside window → silent', $svc->failure('api:axo3', 'http_500', [], $t + 7200) === null);
$check('A5 window ran out → Escalation', $svc->failure('api:axo3', 'http_500', [], $t + 600 + 4 * 3600) === AlertKind::Escalation);
$check('A6 further failure → no second escalation', $svc->failure('api:axo3', 'http_500', [], $t + 600 + 5 * 3600) === null);
$check('A7 success after outage → Recovery', $svc->success('api:axo3', [], $t + 600 + 6 * 3600) === AlertKind::Recovery);
$check('A8 next success → silent again', $svc->success('api:axo3', [], $t + 600 + 7 * 3600) === null);
$check('A9 exactly three messages for one incident', count($channel->sent) === 3);

// ── B. message contents ──────────────────────────────────────────────────────

[$outage, $escalation, $recovery] = $channel->sent;
$check('B1 outage carries the code', $outage->code === 'network');
$check('B2 outage anchors last_success (A1)', $outage->lastSuccess === $t);
$check('B3 escalation reports the failing duration (4h)', $escalation->failingFor() === 4 * 3600);
$check('B4 escalation carries the LATEST code', $escalation->code === 'http_500');
$check('B5 staleness derives from last success', $escalation->staleFor() === 600 + 4 * 3600);
$check('B6 recovery names the incident start', $recovery->failingSince === $t + 600);

// ── C. re-arming: a new incident alerts again ────────────────────────────────

$check('C1 new failure after recovery → Outage again',
    $svc->failure('api:axo3', 'network', [], $t + 8 * 3600) === AlertKind::Outage);
$check('C2 escalation window re-armed',
    $svc->failure('api:axo3', 'network', [], $t + 13 * 3600) === AlertKind::Escalation);

// ── D. state survives the instance (release-switch shape) ────────────────────

$channel2 = new RecordingChannel();
$svc2     = new AlertService($work, [$channel2], escalationSeconds: 4 * 3600);
$check('D1 fresh instance still knows the running outage (silent)',
    $svc2->failure('api:axo3', 'network', [], $t + 13 * 3600 + 60) === null);
$check('D2 fresh instance can close it (Recovery)',
    $svc2->success('api:axo3', [], $t + 14 * 3600) === AlertKind::Recovery);

// ── E. sources are independent; channels are contained ──────────────────────

$check('E1 other source unaffected', $svc->failure('backup:nightly', 'exit_1', [], $t) === AlertKind::Outage);

$recording = new RecordingChannel();
$mixed     = new AlertService($work . '/mixed', [new BrokenChannel(), $recording]);
$survived  = true;
try {
    $kind = $mixed->failure('api:test', 'network', [], $t);
} catch (Throwable) {
    $survived = false;
    $kind     = null;
}
$check('E2 broken channel does not break the caller', $survived && $kind === AlertKind::Outage);
$check('E3 … and the next channel still fired', count($recording->sent) === 1);

// ── cleanup + result ─────────────────────────────────────────────────────────

$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? $rm($f) : unlink($f);
    }
    @rmdir($dir);
};
if ($failures === 0) {
    $rm($work);
}

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
