<?php

namespace Z77\Module\Contact\Roles;

use Z77\Module\Contact\Services\ContactException;

/**
 * A role's module refused to switch the role on or off. The message is the German
 * sentence the user sees (what is missing, or what is possible instead) — the
 * contact editor shows it unchanged and reverts the switch.
 */
final class ContactRoleRefusedException extends ContactException
{
}
