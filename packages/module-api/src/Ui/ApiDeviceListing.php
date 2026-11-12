<?php

namespace Z77\Module\Api\Ui;

use Z77\Module\Api\Entities\ApiDevice,
    Z77\Shared\Listing\Column,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Listing\ListState,
    Z77\Shared\Listing\Parsers
;

/**
 * Stammdaten › System › Geräte as a standard list (listing.md, board «Geräte» 2026-11-12):
 * state icon (opens the device window) · ⋮ (context menu) · Name · Besitzer · Berechtigungen ·
 * Gerät · Gekoppelt · Zuletzt benutzt · Status. Name and owner searched, three columns sort,
 * newest use first. The extra `view` is the toolbar's tabs: «Aktiv» (live devices, the
 * default) · «Alle» (the blocked ones too — who captured what stays readable).
 *
 * The rows live in a FILE collection (`framework/api/devices.json`, a handful per
 * installation), so the search runs here over the whole set, not in SQL — the same contract:
 * strictly parsed values, the sort from a fixed `match`, never from input.
 */
final class ApiDeviceListing
{
    public const ID = 'api-device-find';

    public const LIMIT = 50;

    /** @var list<string> the tabs, the first is the default */
    public const VIEWS = ['active', 'all'];

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Gerät öffnen'),
            Column::slot('Aktionen', '1.6rem'),
            Column::search('f_label', 'Name', 'minmax(8rem, 1fr)', 'label', Parsers::text()),
            Column::search('f_owner', 'Besitzer', 'minmax(9rem, 1fr)', 'owner', Parsers::text()),
            Column::plain('Berechtigungen', 'minmax(9rem, 1.2fr)', priority: '2'),
            Column::plain('Gerät', 'minmax(7rem, 1fr)', priority: '3'),
            Column::plain('Gekoppelt', '6.5rem', 'created', priority: '3', descendingFirst: true),
            Column::plain('Zuletzt benutzt', '8rem', 'used', descendingFirst: true),
            Column::plain('Status', '5.5rem'),
        ], 'used', self::LIMIT, ['view' => self::VIEWS]);
    }

    /**
     * The devices the state asks for: the view, the two searches (case-insensitive, a part of
     * the text), then the sort.
     *
     * @param list<ApiDevice>          $devices
     * @param callable(ApiDevice): string $ownerName the owner as the row shows him
     * @return list<ApiDevice>
     */
    public static function apply(array $devices, ListState $state, callable $ownerName): array
    {
        $label = self::needle($state->parsed('f_label'));
        $owner = self::needle($state->parsed('f_owner'));
        $live  = $state->extra('view') !== 'all';

        $rows = array_values(array_filter($devices, static function (ApiDevice $d) use ($label, $owner, $live, $ownerName): bool {
            return (!$live || !$d->isRevoked())
                && ($label === '' || str_contains(mb_strtolower($d->getLabel()), $label))
                && ($owner === '' || str_contains(mb_strtolower($ownerName($d)), $owner));
        }));

        $key = static fn(ApiDevice $d): string|int => match ($state->sort) {
            'label'   => mb_strtolower($d->getLabel()),
            'owner'   => mb_strtolower($ownerName($d)),
            'created' => self::stamp($d->getCreatedAt()),
            default   => self::stamp($d->getLastUsedAt()),
        };
        usort($rows, static function (ApiDevice $a, ApiDevice $b) use ($key, $state): int {
            $order = $key($a) <=> $key($b) ?: (int) $a->getId() <=> (int) $b->getId();

            return $state->descending ? -$order : $order;
        });

        return $rows;
    }

    /**
     * «heute 08:14», «gestern 17:55», else the date — `last_used_at` is written at most once an
     * hour (ApiDevices::TOUCH_SECONDS), so no seconds pretend a precision it does not have.
     */
    public static function when(string $iso, ?int $now = null): string
    {
        $stamp = self::stamp($iso);
        if ($stamp === 0) {
            return '—';
        }
        $now   = $now ?? time();
        $day   = date('Y-m-d', $stamp);

        return match ($day) {
            date('Y-m-d', $now)                       => 'heute ' . date('H:i', $stamp),
            date('Y-m-d', strtotime('-1 day', $now))  => 'gestern ' . date('H:i', $stamp),
            default                                   => date('d.m.Y', $stamp),
        };
    }

    /** dd.mm.yyyy, or «—». */
    public static function date(string $iso): string
    {
        $stamp = self::stamp($iso);

        return $stamp === 0 ? '—' : date('d.m.Y', $stamp);
    }

    /** dd.mm.yyyy HH:MM, or «—». */
    public static function dateTime(string $iso): string
    {
        $stamp = self::stamp($iso);

        return $stamp === 0 ? '—' : date('d.m.Y H:i', $stamp);
    }

    private static function needle(mixed $parsed): string
    {
        return is_string($parsed) ? mb_strtolower(trim($parsed)) : '';
    }

    private static function stamp(string $iso): int
    {
        $stamp = $iso === '' ? false : strtotime($iso);

        return $stamp === false ? 0 : $stamp;
    }
}
