<?php

namespace Z77\Module\Mailing\Repositories;

use Z77\Module\Mailing\Entities\OutboxMail;

/**
 * What the outbox screen (Service › Postausgang) searches for — the view tab
 * plus one value per column magnifier, each optional, all combined (AND).
 * Built from the list state by {@see \Z77\Module\Mailing\Ui\OutboxListing::search()};
 * read by {@see OutboxMailRepository::search()} / {@see OutboxMailRepository::countSearch()}.
 * Every criterion is already PARSED, so the repository binds values and never
 * interprets input.
 */
final class OutboxSearch
{
    /** The view tabs, the first is the default: Wartend · Gesendet · Fehlgeschlagen · Alle. */
    public const VIEWS = ['queued', 'sent', 'failed', 'all'];

    /** Columns the list can sort by — the key is the URL value, the ORDER BY is the repository's. */
    public const SORTS = ['created', 'recipient', 'subject', 'source'];

    /** A batch id as `queueBatch()` draws it — the list's `batch` extra accepts exactly this. */
    public const BATCH_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param string      $view       one of {@see VIEWS}; `queued` includes the mails being sent right now
     * @param string|null $from       `Y-m-d`, inclusive — the day the mail was queued
     * @param string|null $to         `Y-m-d`, inclusive
     * @param string|null $recipient  contained in the address or the name
     * @param string|null $subject    contained in the subject
     * @param string|null $source     contained in the source reference
     * @param string      $batch      one `queueBatch()` run (32 hex) — the send action's «Postausgang ansehen»; '' = every batch
     */
    public function __construct(
        public readonly string $view = 'queued',
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $recipient = null,
        public readonly ?string $subject = null,
        public readonly ?string $source = null,
        public readonly string $sort = 'created',
        public readonly bool $descending = true,
        public readonly string $batch = '',
    ) {
        if (!in_array($view, self::VIEWS, true)) {
            throw new \InvalidArgumentException("Unknown outbox view '{$view}'");
        }
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown outbox sort '{$sort}'");
        }
        if ($batch !== '' && preg_match(self::BATCH_PATTERN, $batch) !== 1) {
            throw new \InvalidArgumentException('An outbox batch id is 32 hex characters');
        }
    }

    /** @return list<string> the states the view shows; [] = every state */
    public function states(): array
    {
        return match ($this->view) {
            'queued' => [OutboxMail::STATE_QUEUED, OutboxMail::STATE_SENDING],
            'sent'   => [OutboxMail::STATE_SENT],
            'failed' => [OutboxMail::STATE_FAILED],
            default  => [],
        };
    }
}
