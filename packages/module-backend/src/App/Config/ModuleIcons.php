<?php
namespace Z77\Module\Backend\App\Config;

/**
 * Presentation-only map: backend navigation top-group (section) → icon sprite id, for the
 * shell topbar's module switcher (css-backend.md shell rebuild, 2026-07-03). The icon is
 * NOT stored on the Navigation entity (deliberately kept out of the data model) — it is a
 * config lookup keyed by the section's display name (lowercased). Provisional glyphs; a
 * missing entry falls back to the neutral grid icon.
 */
class ModuleIcons
{
    private const MAP = [
        'webseiten'  => 'icon-globe',
        'stammdaten' => 'icon-database',
        'drive'      => 'icon-hard-drive',
        'service'    => 'icon-wrench',
    ];

    private const FALLBACK = 'icon-grid';

    public static function forSection(string $name): string
    {
        return self::MAP[mb_strtolower(trim($name))] ?? self::FALLBACK;
    }
}
