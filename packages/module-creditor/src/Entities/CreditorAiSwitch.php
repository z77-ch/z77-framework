<?php

namespace Z77\Module\Creditor\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * The owner's switch «KI-Erkennung» of the Kreditorenjournal (owner 2026-11-16: «ja schalter in der
 * oberfläche bauen, neben PDF hochladen button») — ONE record, written only through
 * {@see \Z77\Module\Creditor\Recognition\Ai\AiSwitch}. Two things, two places (Rule 2):
 *
 *   - `creditorAi.enabled` in `config/client/kibot.inc.php` = the AI is RELEASED for this
 *     installation (key, privacy notes — the installer's decision; {@see \Z77\Module\Creditor\Recognition\Ai\AiSettings});
 *   - this record = the owner's runtime choice, on or off, while it is released.
 *
 * No record = on (the default — an installation that released the AI before the switch existed
 * keeps running it). File-based like the operator's job schedules (`framework/jobs/schedules.json`):
 * a record that belongs to the operator and survives every update; `data/` lies in `shared/` of the
 * release layout, read by the web request and the cron job alike.
 */
#[Entity('file', 'framework/creditor/ai_switch.json')]
class CreditorAiSwitch
{
    use ArrayMappable;

    /** Server-controlled — no setter; the store assigns it. */
    private ?int $id = null;

    private bool $on = true;

    /** When the owner last switched it (ISO 8601); null = never. */
    #[Clean('nullable', 'text')]
    private ?string $changedAt = null;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?int { return $this->id; }
    public function isOn(): bool { return $this->on; }
    public function getChangedAt(): ?string { return $this->changedAt; }

    /** Only a real `true` (or `1` / `"1"` / `"true"` of a hand-edited file) is on — anything else is off. */
    public function setOn(bool|int|string $on): void
    {
        $this->on = $on === true || $on === 1 || $on === '1' || $on === 'true';
    }

    public function setChangedAt(?string $changedAt): void { $this->changedAt = $changedAt; }
}
