<?php

namespace Z77\Module\Mailing\Outbox;

use Z77\Core\Services\ModuleManager;

/**
 * The outbox's tunables — ONE place (Rule 2): the key `outbox` of the module
 * config `mailingConfig` holds the defaults; a project records only its
 * DEVIATION in the extension file
 * `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php`
 * (`ModuleManager::getConfigExtensions()`), merged key by key over the
 * defaults:
 *
 * ```php
 * <?php return ['batchSize' => 20];   // 20 mails per pass, the rest as shipped
 * ```
 *
 *   - `batchSize`    (10) — mails one job slice sends at most;
 *   - `pauseSeconds` (60) — the wait after a slice while mails remain: 10 per
 *     minute is what the old system did and the providers accepted (owner
 *     2026-10-22: «ein Batch versendete 10 Mails, wartete 1 Minute»);
 *   - `maxAttempts`  (3)  — attempts per mail before it is `failed`;
 *   - `retryMinutes` ([5, 15, 60]) — the wait after the 1st, 2nd, 3rd …
 *     failure; the last value repeats when `maxAttempts` asks for more.
 *
 * Values out of range are clamped, not refused: a typo in a project file must
 * not stop the mail.
 */
final class OutboxSettings
{
    public const CONFIG_KEY = 'outbox';

    public const DEFAULTS = [
        'batchSize'    => 10,
        'pauseSeconds' => 60,
        'maxAttempts'  => 3,
        'retryMinutes' => [5, 15, 60],
    ];

    /** @param list<int> $retryMinutes */
    public function __construct(
        public readonly int $batchSize = 10,
        public readonly int $pauseSeconds = 60,
        public readonly int $maxAttempts = 3,
        public readonly array $retryMinutes = [5, 15, 60],
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $values += self::DEFAULTS;
        $retry   = array_values(array_filter(
            array_map(static fn($m) => is_numeric($m) ? max(1, (int) $m) : null, (array) $values['retryMinutes']),
            static fn($m) => $m !== null
        ));

        return new self(
            max(1, min(500, (int) $values['batchSize'])),
            max(0, min(3600, (int) $values['pauseSeconds'])),
            max(1, min(20, (int) $values['maxAttempts'])),
            $retry === [] ? self::DEFAULTS['retryMinutes'] : $retry,
        );
    }

    /** The module config's `outbox` defaults with every `outboxConfig.inc.php` extension merged over them. */
    public static function fromModules(ModuleManager $modules): self
    {
        $values = (array) ($modules->getModuleConfig('mailing')?->get(self::CONFIG_KEY, []) ?? []);
        foreach ($modules->getConfigExtensions('mailing', self::CONFIG_KEY) as $extension) {
            $values = array_replace($values, $extension);
        }

        return self::fromArray($values);
    }

    /** Seconds to wait after the `$failures`-th failure (1-based). */
    public function retryDelaySeconds(int $failures): int
    {
        $index = max(0, min(count($this->retryMinutes) - 1, $failures - 1));

        return $this->retryMinutes[$index] * 60;
    }
}
