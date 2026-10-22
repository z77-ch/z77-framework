<?php

namespace Z77\Module\Contact\Dashboard;

use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;

/**
 * contact's entry on the backend dashboard (ADR-052), registered under
 * `dashboard` in `contactConfig`: «+ Neuer Kontakt» in the head of the tile
 * that holds the contact list — the contact dialog, as the list's action cell
 * opens it (`data-fetch-get`). No counter: a number of contacts asks for
 * nothing (owner 2026-10-22).
 */
final class ContactDashboard implements DashboardContributor
{
    public function entries(AuthUser $user): iterable
    {
        yield DashboardEntry::create('Neuer Kontakt', ['backend', 'contact', 'contact', 'add'], DashboardEntry::OPEN_DIALOG);
    }
}
