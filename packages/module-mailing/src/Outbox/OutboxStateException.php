<?php

namespace Z77\Module\Mailing\Outbox;

/**
 * A retry or cancel the mail's current state does not allow — it was sent,
 * cancelled or picked up by the job in the meantime, or it does not exist.
 * The message is German and meant for the screen.
 */
final class OutboxStateException extends \RuntimeException
{
}
