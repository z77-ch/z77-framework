<?php

namespace Z77\Module\Mailing\Outbox;

/**
 * A draft the outbox refuses — nothing of the batch was written. Carries one
 * German message per problem, keyed by the draft's index in the batch, so a
 * caller can say «Empfänger 12: ungültige Adresse».
 */
final class InvalidOutboxMailException extends \InvalidArgumentException
{
    /** @param array<int, list<string>> $errors draft index => messages */
    public function __construct(public readonly array $errors)
    {
        $first = '';
        foreach ($errors as $index => $messages) {
            $first = 'Mail ' . ($index + 1) . ': ' . ($messages[0] ?? '');
            break;
        }
        $count = array_sum(array_map('count', $errors));
        parent::__construct($count > 1 ? $first . " ({$count} Fehler insgesamt)" : $first);
    }
}
