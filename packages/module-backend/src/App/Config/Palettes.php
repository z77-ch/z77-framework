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
            // First = the default (UserPreferences); its tokens are the `.be` base block, the others
            // override it under `[data-be-palette="<id>"]`.
            ['id' => 'salbei',   'name' => 'Salbei',   'accent' => '#1f6b5c'],
            ['id' => 'werkbank', 'name' => 'Werkbank', 'accent' => '#4f46e5'],
            ['id' => 'citrus',   'name' => 'Citrus',   'accent' => '#5cb338'],
            ['id' => 'coral',    'name' => 'Coral',    'accent' => '#ff6b4a'],
            ['id' => 'lagune',   'name' => 'Lagune',   'accent' => '#0aa5b5'],
            ['id' => 'beere',    'name' => 'Beere',    'accent' => '#c2185b'],
            ['id' => 'sonne',    'name' => 'Sonne',    'accent' => '#e08a1c'],
        ];
    }
}
