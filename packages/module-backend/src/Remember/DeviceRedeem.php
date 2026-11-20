<?php

namespace Z77\Module\Backend\Remember;

/**
 * What {@see RememberedDevices::redeem()} found for a presented device cookie.
 *
 * - ROTATED: the current token. A NEW one was stored; `cookieValue` must go to the device.
 * - RECENT:  the token rotated away a moment ago (a second tab of the same browser start). The
 *            device may sign in, nothing changes, no cookie is sent.
 * - REUSED:  the device is known, the token is not its current one: a copy is in use somewhere.
 *            The device is already removed; the caller ends the user's sessions.
 * - UNKNOWN: nothing to act on (malformed, no such device, expired).
 */
final class DeviceRedeem
{
    public const ROTATED = 'rotated';
    public const RECENT  = 'recent';
    public const REUSED  = 'reused';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $outcome,
        public readonly int $userId = 0,
        public readonly int $authEpoch = 0,
        public readonly ?string $cookieValue = null,
        public readonly int $expiresAt = 0,
    ) {
    }

    /** The device proved itself: the caller may look at the user. */
    public function accepted(): bool
    {
        return $this->outcome === self::ROTATED || $this->outcome === self::RECENT;
    }
}
