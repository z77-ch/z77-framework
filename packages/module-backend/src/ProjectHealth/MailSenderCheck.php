<?php

namespace Z77\Module\Backend\ProjectHealth;

use Z77\Core\DI;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 9 («Ersteinrichtung», owner 2026-11-19): the installation cannot send real mail —
 * `config/client/mail.inc.php` has `enabled = false` (or is absent), the transport is `file`
 * (delivery to disk, development only), or no `fromAddress` is set. Read exactly as the kernel
 * `Mailer::create()` reads it: ONE `getBaseConfig('config/mail')`. Owned by module-backend (it
 * owns the service screens; the kernel mail has no module config). The finding is kept for
 * {@see label()}; the three causes are checked in this order, the first one names the line.
 */
final class MailSenderCheck extends SetupCheck
{
    private string $label = 'E-Mail-Absender erfassen';

    /** @param array<string, mixed>|null $config the mail config; null = read it like the Mailer (harness only) */
    public function __construct(private readonly ?array $config = null) {}

    public function id(): string
    {
        return 'mail.sender';
    }

    public function order(): int
    {
        return 90;
    }

    public function target(): array
    {
        return ['backend', 'service', 'email-settings', 'list'];
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isOpen(): bool
    {
        $get = $this->config !== null
            ? fn(string $key, mixed $default = null): mixed => $this->config[$key] ?? $default
            : fn(string $key, mixed $default = null): mixed => DI::getConfigManager()->getBaseConfig('config/mail', throwError: false)->get($key, $default);

        if (!(bool) $get('enabled', false)) {
            $this->label = 'E-Mail-Versand einschalten';

            return true;
        }
        if ((string) $get('transport', 'smtp') === 'file') {
            $this->label = 'E-Mail-Versand einrichten (Transport «file»)';

            return true;
        }
        if (trim((string) $get('fromAddress', '')) === '') {
            $this->label = 'E-Mail-Absender erfassen';

            return true;
        }

        return false;
    }
}
