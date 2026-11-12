<?php

namespace Z77\Module\Api\Ui;

use Z77\Module\Api\Entities\ApiAppUser,
    Z77\Shared\Listing\Column,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Listing\ListState,
    Z77\Shared\Listing\Parsers
;

/**
 * Stammdaten › System › App-Benutzer as a standard list (listing.md, board «App-Benutzer»
 * approved 2026-11-12): state icon (opens the app-user window) · ⋮ (context menu) · Verknüpfte
 * Person · App-E-Mail · Funktionen · Geräte · Zuletzt angemeldet · Status. Person and e-mail are
 * searched, three columns sort, the latest sign-in first. The extra `view` is the toolbar's tabs:
 * «Aktiv» (the default) · «Alle» (deactivated ones too — who captured what stays readable).
 *
 * The rows live in a FILE collection (`framework/api/app_users.json`, a handful per
 * installation), so the search runs here over the whole set, not in SQL — the same contract:
 * strictly parsed values, the sort from a fixed `match`, never from input.
 */
final class ApiAppUserListing
{
    public const ID = 'api-app-user-find';

    public const LIMIT = 50;

    /** @var list<string> the tabs, the first is the default */
    public const VIEWS = ['active', 'all'];

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('App-Benutzer öffnen'),
            Column::slot('Aktionen', '1.6rem'),
            Column::search('f_person', 'Verknüpfte Person', 'minmax(9rem, 1fr)', 'person', Parsers::text()),
            Column::search('f_email', 'App-E-Mail', 'minmax(10rem, 1.15fr)', 'email', Parsers::text()),
            Column::plain('Funktionen', 'minmax(8rem, 0.9fr)', priority: '2'),
            Column::plain('Geräte', '4.5rem', numeric: true, priority: '3'),
            Column::plain('Zuletzt angemeldet', '9rem', 'login', descendingFirst: true),
            Column::plain('Status', '6.5rem'),
        ], 'login', self::LIMIT, ['view' => self::VIEWS]);
    }

    /**
     * The app users the state asks for: the view, the two searches (case-insensitive, a part of
     * the text), then the sort.
     *
     * @param list<ApiAppUser>               $appUsers
     * @param callable(ApiAppUser): string   $personName the linked person as the row shows it
     * @return list<ApiAppUser>
     */
    public static function apply(array $appUsers, ListState $state, callable $personName): array
    {
        $person = self::needle($state->parsed('f_person'));
        $email  = self::needle($state->parsed('f_email'));
        $active = $state->extra('view') !== 'all';

        $rows = array_values(array_filter($appUsers, static function (ApiAppUser $u) use ($person, $email, $active, $personName): bool {
            return (!$active || $u->isActive())
                && ($person === '' || str_contains(mb_strtolower($personName($u)), $person))
                && ($email === '' || str_contains($u->getEmail(), $email));
        }));

        $key = static fn(ApiAppUser $u): string|int => match ($state->sort) {
            'person' => mb_strtolower($personName($u)),
            'email'  => $u->getEmail(),
            default  => self::stamp($u->getLastLoginAt()),
        };
        usort($rows, static function (ApiAppUser $a, ApiAppUser $b) use ($key, $state): int {
            $order = $key($a) <=> $key($b) ?: (int) $a->getId() <=> (int) $b->getId();

            return $state->descending ? -$order : $order;
        });

        return $rows;
    }

    /**
     * «heute 08:14», «gestern 17:55», else the date — a device's `last_used_at` is written at
     * most once an hour (ApiDevices::TOUCH_SECONDS), so no seconds pretend a precision it does not
     * have.
     */
    public static function when(string $iso, ?int $now = null): string
    {
        $stamp = self::stamp($iso);
        if ($stamp === 0) {
            return '—';
        }
        $now = $now ?? time();

        return match (date('Y-m-d', $stamp)) {
            date('Y-m-d', $now)                      => 'heute ' . date('H:i', $stamp),
            date('Y-m-d', strtotime('-1 day', $now)) => 'gestern ' . date('H:i', $stamp),
            default                                  => date('d.m.Y', $stamp),
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
