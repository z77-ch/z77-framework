<?php

namespace Z77\Module\Backend\TwoFactor;

/**
 * What {@see BackendTwoFactor::check()} found. Locked and Unavailable also mean the right code was
 * refused: Locked by the counter, Unavailable because there is no counter to trust.
 */
enum TotpCheck
{
    case Passed;
    case Wrong;
    case Locked;
    case Unavailable;

    /** What the person is told. Seen only after a right password or a valid link: it gives nothing away. */
    public function refusal(): string
    {
        return match ($this) {
            self::Passed      => '',
            self::Wrong       => 'Der Code ist falsch oder wurde bereits verwendet. Bitte warte auf den nächsten Code.',
            self::Locked      => 'Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.',
            self::Unavailable => 'Die Anmeldung mit Code ist wegen eines technischen Problems gerade nicht möglich. Bitte den Betreiber informieren.',
        };
    }
}
