<?php

namespace Z77\Module\Contact\Roles;

/**
 * What a {@see ContactRoleInterface} answers about one contact — the contact
 * editor renders it as it is and adds nothing of its own.
 *
 *   - `on`         the contact has the role;
 *   - `summary`    one line of state («Kundennummer 1000 · 30 Tage netto»), '' = none;
 *   - `switchable` the switch may be flipped NOW (the server checks again on the flip);
 *   - `reason`     why not, in German, when `switchable` is false;
 *   - `linkUrl`    a dialog of the role's module for the further settings
 *                  (opened with `data-fetch-get`), '' = none; `linkLabel` its button text;
 *   - `offConfirm` the question asked BEFORE switching the role off, in German — what
 *                  switching off destroys («Das Debitorenprofil … wird gelöscht»); '' = off
 *                  acts at once (a role whose off loses nothing). With a sentence the
 *                  contact dialog asks first and switches off only on the confirmation,
 *                  with an entity token (owner 2026-10-13, review M5 — fetch.md: a
 *                  destructive step is confirmed, a toggle alone is non-destructive).
 */
final readonly class ContactRoleState
{
    public function __construct(
        public bool $on,
        public string $summary = '',
        public bool $switchable = true,
        public string $reason = '',
        public string $linkUrl = '',
        public string $linkLabel = '',
        public string $offConfirm = '',
    ) {}
}
