<?php

namespace Z77\Module\Creditor\Recognition\Ai;

use Z77\Module\Creditor\Entities\CreditorAiSwitch;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The owner's switch «KI-Erkennung» (owner 2026-11-16) — next to «PDF hochladen» in the
 * Kreditorenjournal. The AI step of the recognition runs only when BOTH hold
 * (`CreditorRecognitionService::recognize()` asks both):
 *
 *   - released: {@see AiSettings::enabled()} — `creditorAi.enabled` in kibot's file and a readable
 *     key (the installer's decision; without it the switch is not shown and refuses a toggle);
 *   - switched on: {@see isOn()} — the record {@see CreditorAiSwitch}; never set = ON.
 *
 * Read from the file at every call — no cache: the job asks once per invoice, so switching off
 * stops every AI step that has not started yet; one already waiting on the AI finishes.
 */
final class AiSwitch
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * On unless the owner switched it off. Never set → on. A store that cannot be read (a corrupt
     * file) → OFF and logged: an unreadable choice must not start paid AI calls.
     */
    public function isOn(): bool
    {
        try {
            return $this->record()?->isOn() ?? true;
        } catch (\Throwable $e) {
            error_log('creditor AI switch: not readable, treated as off — ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    /** The owner's choice — set, not flipped, so a stale page cannot invert it. */
    public function set(bool $on): void
    {
        $record = $this->record() ?? new CreditorAiSwitch();
        $record->setOn($on);
        $record->setChangedAt(date(DATE_ATOM));
        $this->em->persist($record);
        $this->em->flush();
    }

    private function record(): ?CreditorAiSwitch
    {
        foreach ($this->em->getRepository(CreditorAiSwitch::class)->findAll() as $record) {
            if ($record instanceof CreditorAiSwitch) {
                return $record;
            }
        }

        return null;
    }
}
