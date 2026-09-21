<?php

namespace Z77\Persistence\Doctrine\Console;

use Doctrine\Migrations\Version\Comparator,
    Doctrine\Migrations\Version\Version
;

/**
 * Orders migrations by their timestamp across ALL namespaces.
 *
 * Doctrine's default compares the full class name, so with one namespace
 * per module (decision 13) every migration of `Z77\Module\Contact\…` would
 * run before the first of `Z77\Module\Debtor\…`, whatever their dates — a
 * debtor migration written in March would precede a contact migration
 * written in June, and «latest» would be whichever module sorts last. The
 * class name's `Version{YmdHis}` part is what says WHEN a migration was
 * written; that is the order it was tested in and the order it runs in.
 * The namespace breaks a tie (two migrations generated in the same second).
 */
final class ChronologicalComparator implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        $byTimestamp = strcmp(self::shortName((string)$a), self::shortName((string)$b));

        return $byTimestamp !== 0 ? $byTimestamp : strcmp((string)$a, (string)$b);
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
