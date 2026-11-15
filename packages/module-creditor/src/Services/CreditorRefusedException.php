<?php

namespace Z77\Module\Creditor\Services;

/**
 * A creditor write was refused as a whole — the message is German and names the next step;
 * `reason` is the stable code a caller branches on. Nothing was written.
 */
final class CreditorRefusedException extends CreditorException
{
    /** The creditor is gone, or carries another version than the caller saw. */
    public const CHANGED = 'changed';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
