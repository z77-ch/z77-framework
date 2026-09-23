<?php

namespace Z77\Module\Mandator\Services;

/**
 * A second mandator was about to be created (owner decision E1: ONE
 * record). Raised by `MandatorService::save()` when a row exists — before
 * the write when the check sees it, or from the primary-key violation when
 * two first saves raced (the id is fixed, the database decides). The
 * screen answers with this message and reloads the existing record.
 */
final class MandatorAlreadyExistsException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Der Mandant ist bereits erfasst — die Maske neu laden und den bestehenden Eintrag bearbeiten.', 0, $previous);
    }
}
