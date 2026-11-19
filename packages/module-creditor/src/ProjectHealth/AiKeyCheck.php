<?php

namespace Z77\Module\Creditor\ProjectHealth;

use Z77\Module\Creditor\Recognition\Ai\AiSettings;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 10 («Ersteinrichtung», owner 2026-11-19): the AI receipt recognition is RELEASED
 * (`creditorAi.enabled === true` in `config/client/kibot.inc.php`) but its key file
 * (`paths.keyFile`, kibot's) is unset, missing, empty or unreadable — the AI step would never
 * run. The owner's runtime switch «KI-Erkennung» is deliberately not asked: without a key it is
 * not shown ({@see \Z77\Module\Creditor\Recognition\Ai\AiSwitch}). The key is never read out, only
 * its presence ({@see AiSettings::hasKey()}). Target: the Kreditorenjournal, where the switch
 * lives (there is no settings screen — the key is a file the installer places).
 */
final class AiKeyCheck extends SetupCheck
{
    /** @param AiSettings|null $settings null = the installation's (harness only) */
    public function __construct(private readonly ?AiSettings $settings = null) {}

    public function id(): string
    {
        return 'creditor.ai-key';
    }

    public function order(): int
    {
        return 100;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'creditor-journal', 'list'];
    }

    public function label(): string
    {
        return 'KI-Schlüssel fehlt';
    }

    public function isOpen(): bool
    {
        $settings = $this->settings ?? AiSettings::forInstallation();

        return $settings->switchedOn() && !$settings->hasKey();
    }
}
