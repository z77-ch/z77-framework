<?php
namespace Z77\Module\Backend\App\Config;

/**
 * Presentation-only map: backend navigation top-group (section) → icon sprite id, for the
 * shell topbar's module switcher (css-backend.md shell rebuild, 2026-07-03). The icon is
 * NOT stored on the Navigation entity (deliberately kept out of the data model) — it is a
 * config lookup keyed by the section's display name (lowercased). Provisional glyphs; a
 * missing entry falls back to the neutral grid icon.
 *
 * What may be added here: section names ANY project could carry («Kunden»,
 * «Stammdaten»). What may not: a name that only makes sense in one product —
 * that project overrides this file whole instead. The distinction matters
 * because this map ships to every installation.
 */
class ModuleIcons
{
    private const MAP = [
        'webseiten'  => 'icon-globe',
        'stammdaten' => 'icon-database',
        'kunden'     => 'icon-building',
        'drive'      => 'icon-hard-drive',
        'service'    => 'icon-wrench',
    ];

    private const FALLBACK = 'icon-grid';

    public static function forSection(string $name): string
    {
        return self::MAP[mb_strtolower(trim($name))] ?? self::FALLBACK;
    }
}
