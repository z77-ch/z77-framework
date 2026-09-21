<?php

namespace Z77\Persistence\Doctrine\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * A number range: the last number handed out under a name — the row that
 * `NumberRangeRepository::next()` locks with `SELECT … FOR UPDATE` (ADR-039
 * decision 15, ADR-042 decision 9).
 *
 * The mapping IS the table definition (`number_range`): `SchemaTool` builds
 * it for the test harness, and the package's first migration
 * (`res/migrations/Version20260921000000.php`) creates the identical table
 * in production — the class is announced through
 * `EntityManagerFactory::PACKAGE_ENTITIES`, not through a module config.
 *
 * Key model: ONE string, chosen by the calling module, e.g. `invoice`,
 * `credit-note`, `journal-entry.2026` (entry numbers per fiscal year, ADR-042
 * decision 9). Composing the name is the module's business; the range knows
 * nothing about documents or years. Names compare case-insensitively
 * (`utf8mb4_unicode_ci`, decision 18): `Invoice` and `invoice` are one range.
 *
 * Never loaded, persisted or modified through the ORM by a consumer: every
 * number comes from `next()` inside the caller's transaction, and a row is
 * created there on the first draw. The class exists so the schema exists.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'number_range')]
class NumberRange
{
    public const TABLE = 'number_range';

    /** Longest name the column holds; `next()` refuses longer ones before SQL. */
    public const NAME_LENGTH = 64;

    #[ORM\Id, ORM\Column(length: self::NAME_LENGTH)]
    private string $name;

    #[ORM\Column(name: 'last_number', options: ['unsigned' => true])]
    private int $lastNumber = 0;

    /** No accessors on purpose: the row is read and written by `next()`'s SQL only. */
    private function __construct()
    {
    }
}
