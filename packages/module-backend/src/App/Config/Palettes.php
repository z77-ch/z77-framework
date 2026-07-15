<?php
namespace Z77\Module\Backend\App\Config;

/**
 * Backend palette catalog.
 *
 * Lists every palette the user can choose in the appearance panel.
 * Token values per palette live in `packages/module-backend/res/scss/tokens/_colors.scss`
 * under `[data-be-palette="<id>"]` selectors — keep IDs in sync with the SCSS source.
 */
final class Palettes
{
    public static function all(): array
    {
        return [
            ['id' => 'werkbank', 'name' => 'Werkbank', 'accent' => '#3f5a3a'],
            ['id' => 'citrus',   'name' => 'Citrus',   'accent' => '#5cb338'],
            ['id' => 'coral',    'name' => 'Coral',    'accent' => '#ff6b4a'],
            ['id' => 'lagune',   'name' => 'Lagune',   'accent' => '#0aa5b5'],
            ['id' => 'beere',    'name' => 'Beere',    'accent' => '#c2185b'],
            ['id' => 'sonne',    'name' => 'Sonne',    'accent' => '#e08a1c'],
        ];
    }
}
