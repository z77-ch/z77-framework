<?php

namespace Z77\Module\Api\Services;

/**
 * The actor may not do this to this app user (ADR-058 A9 and §4 «Rights»): not an ADMIN, the
 * linked person's roles lie above the actor's, or a scope the actor could not hold himself. A
 * screen shows the refusal; the message is English (exception text), the screen words it.
 */
final class ApiAppUserDenied extends \RuntimeException
{
}
