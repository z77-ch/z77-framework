<?php

namespace Z77\Module\Mailing\Attachments;

/**
 * One attachment reference of an outbox mail: `type:id`, e.g. `invoice-pdf:123`.
 * The type names a registered {@see AttachmentProvider}; the id is the
 * provider's own and opaque to the outbox. Lower-case kebab type, an id of
 * printable characters without whitespace — what goes into the database is a
 * key, not a file name.
 */
final class AttachmentReference
{
    public const TYPE_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/';

    private const PATTERN = '/^([a-z][a-z0-9-]{0,39}):(\S{1,120})$/';

    private function __construct(public readonly string $type, public readonly string $id)
    {
    }

    /** @throws \InvalidArgumentException not of the form `type:id` */
    public static function parse(string $reference): self
    {
        if (preg_match(self::PATTERN, $reference, $m) !== 1) {
            throw new \InvalidArgumentException("Attachment reference '{$reference}' is not of the form type:id (e.g. invoice-pdf:123).");
        }

        return new self($m[1], $m[2]);
    }

    public function __toString(): string
    {
        return $this->type . ':' . $this->id;
    }
}
