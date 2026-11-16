<?php

namespace Z77\Module\Creditor\Recognition\Ai;

use Z77\Core\Kibot\KibotEndpoint;
use Z77\KiBot\Config;
use Z77\KiBot\KeyStore;

/**
 * The switch and the settings of the creditor AI step (plan §4 step 3, owner answer §11.9):
 * switched like the backend KI-Assistent — the SAME project file `config/client/kibot.inc.php`,
 * the SAME key file (`paths.keyFile`), its own on/off and model under the key {@see SECTION}.
 * No second key store, no second switch place (Rule 2):
 *
 *   return [
 *       'paths'      => ['keyFile' => '/home/<hosting>/.z77/<project>/keys/anthropic.key'],
 *       'creditorAi' => ['enabled' => true],           // only the deviations from DEFAULTS
 *   ];
 *
 * Taken from the shared part of the file (like the backend assistant): `paths.keyFile`,
 * `paths.logDir`, `paths.rateDir` and the `api` section's endpoint, version and fallback beta.
 * Nothing else of the website bot.
 *
 * Off — every unclear invoice is handled by hand, nothing is lost — while z77/kibot is not
 * installed, the file is missing or broken, `creditorAi.enabled` is not `true`, or the key file
 * is not readable ({@see enabled()}). The key itself is never held or exposed here: only
 * {@see keyStore()} hands out kibot's `KeyStore`, which reads it lazily per request.
 *
 * `creditorAi.enabled` RELEASES the AI for the installation (the installer's decision: key,
 * privacy notes). Within that, the owner switches it on and off at runtime with the toolbar switch
 * «KI-Erkennung» of the Kreditorenjournal — {@see AiSwitch} (owner 2026-11-16), a separate thing
 * at its own place; the step runs only when both say yes.
 */
final class AiSettings
{
    /** Key of the creditor section in `config/client/kibot.inc.php`. */
    public const SECTION = 'creditorAi';

    /**
     * Defaults — a project writes only its deviations.
     *
     * - `model`: `claude-opus-5-5` (≈ $4 / $20 per MTok, ≈ 3 Rappen for a two-page invoice); the
     *   owner chooses the final model after a trial with 20 real invoices — a setting, not code.
     * - `effort`: `output_config.effort`; '' = not sent (a model without effort support).
     * - `maxTokens`: thinking counts towards it on current models, so it is not lowballed.
     * - `maxPdfBytes`: checked before anything is sent (the API takes 32 MB per request, base64
     *   inflates by 4/3).
     * - `maxPerDay`: calls per calendar day (server time), counted in `paths.rateDir`.
     */
    public const DEFAULTS = [
        'enabled'            => false,
        'model'              => 'claude-opus-5-5',
        'effort'             => 'medium',
        'maxTokens'          => 16000,
        'maxPdfBytes'        => 10485760,
        'maxPerDay'          => 50,
        'timeoutSeconds'     => 120,
        'serverSideFallback' => true,
    ];

    private const EFFORTS = ['', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @param array<string, mixed> $values the validated creditor section
     * @param array{endpoint: string, version: string, fallbackBeta: string} $api
     */
    private function __construct(
        private readonly array $values,
        private readonly string $keyFile,
        private readonly string $logDir,
        private readonly string $rateDir,
        private readonly array $api,
    ) {}

    /**
     * The installation's settings. Never throws: a missing package or a broken config file
     * yields the switched-off settings.
     *
     * @param string|null $baseDir project root; default ABS_BASE_PATH
     */
    public static function forInstallation(?string $baseDir = null): self
    {
        try {
            $base = KibotEndpoint::baseDir($baseDir);

            return self::fromOverrides(KibotEndpoint::overrides($base), $base);
        } catch (\Throwable) {
            return self::off();
        }
    }

    /**
     * From the overrides the kibot file returns (null = no file / no package). Relative paths
     * resolve against $baseDir exactly as kibot resolves them.
     */
    public static function fromOverrides(?array $overrides, string $baseDir): self
    {
        if ($overrides === null || !class_exists(Config::class)) {
            return self::off();
        }
        $shared = [];
        if (isset($overrides['api']) && is_array($overrides['api'])) {
            $shared['api'] = $overrides['api'];
        }
        foreach (['keyFile', 'logDir', 'rateDir'] as $key) {
            if (isset($overrides['paths'][$key]) && is_string($overrides['paths'][$key])) {
                $shared['paths'][$key] = $overrides['paths'][$key];
            }
        }
        $config  = new Config($shared);
        $section = is_array($overrides[self::SECTION] ?? null) ? $overrides[self::SECTION] : [];

        return new self(
            self::validate($section),
            $config->path('keyFile', $baseDir),
            $config->path('logDir', $baseDir),
            $config->path('rateDir', $baseDir),
            [
                'endpoint'     => (string) $config->get('api.endpoint'),
                'version'      => (string) $config->get('api.version'),
                'fallbackBeta' => (string) $config->get('api.fallbackBeta'),
            ],
        );
    }

    /** Switched on in the file AND a readable key — the step runs. Never reads the key out. */
    public function enabled(): bool
    {
        return $this->switchedOn() && $this->hasKey();
    }

    /** `creditorAi.enabled` is literally `true` (a string «true» or 1 does not switch it on). */
    public function switchedOn(): bool
    {
        return $this->values['enabled'] === true;
    }

    public function hasKey(): bool
    {
        $file = $this->keyFile;

        return $file !== '' && is_file($file) && is_readable($file) && (int) @filesize($file) > 0;
    }

    /** kibot's key reader on the shared key file — the only door to the key. */
    public function keyStore(): KeyStore
    {
        return new KeyStore($this->keyFile);
    }

    public function model(): string
    {
        return $this->values['model'];
    }

    public function effort(): string
    {
        return $this->values['effort'];
    }

    public function maxTokens(): int
    {
        return $this->values['maxTokens'];
    }

    public function maxPdfBytes(): int
    {
        return $this->values['maxPdfBytes'];
    }

    public function maxPerDay(): int
    {
        return $this->values['maxPerDay'];
    }

    public function timeoutSeconds(): int
    {
        return $this->values['timeoutSeconds'];
    }

    public function serverSideFallback(): bool
    {
        return $this->values['serverSideFallback'] && $this->api['fallbackBeta'] !== '';
    }

    public function endpoint(): string
    {
        return $this->api['endpoint'];
    }

    public function apiVersion(): string
    {
        return $this->api['version'];
    }

    public function fallbackBeta(): string
    {
        return $this->api['fallbackBeta'];
    }

    /** Directory of the call log (kibot's `paths.logDir`); '' = not configured. */
    public function logDir(): string
    {
        return $this->logDir;
    }

    /** Directory of the daily counter (kibot's `paths.rateDir`, default `<logDir>/rate`). */
    public function rateDir(): string
    {
        return $this->rateDir;
    }

    public function __debugInfo(): array
    {
        return ['values' => $this->values, 'keyFile' => $this->keyFile, 'logDir' => $this->logDir];
    }

    private static function off(): self
    {
        return new self(self::DEFAULTS, '', '', '', ['endpoint' => '', 'version' => '', 'fallbackBeta' => '']);
    }

    /**
     * Every value checked against its type and range; a wrong one falls back to its default
     * rather than reaching the request.
     *
     * @return array<string, mixed>
     */
    private static function validate(array $section): array
    {
        $values = self::DEFAULTS;
        $values['enabled'] = ($section['enabled'] ?? false) === true;
        $values['serverSideFallback'] = is_bool($section['serverSideFallback'] ?? null)
            ? $section['serverSideFallback']
            : self::DEFAULTS['serverSideFallback'];

        $model = $section['model'] ?? null;
        if (is_string($model) && preg_match('/^[a-z0-9][a-z0-9._:@\-]{1,99}$/', $model) === 1) {
            $values['model'] = $model;
        }
        $effort = $section['effort'] ?? null;
        if (is_string($effort) && in_array($effort, self::EFFORTS, true)) {
            $values['effort'] = $effort;
        }
        foreach (['maxTokens' => 128000, 'maxPdfBytes' => 33554432, 'maxPerDay' => 100000, 'timeoutSeconds' => 600] as $key => $ceiling) {
            $value = $section[$key] ?? null;
            if (is_int($value) && $value > 0 && $value <= $ceiling) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
