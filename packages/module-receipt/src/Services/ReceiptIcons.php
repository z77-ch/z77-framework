<?php

namespace Z77\Module\Receipt\Services;

/**
 * The glyphs of the tile symbols (`ReceiptTemplate::$icon`, E3): the keys and their German names
 * are `ReceiptTemplate::ICONS` (what the backend offers); this is how the phone DRAWS each key — a
 * stroke path on the 24×24 grid in `currentColor`, rendered once as an inline sprite by the app
 * screen (`res/view/templates/App/receipt.tpl.php`). A key without a glyph here, or '', draws
 * {@see DEFAULT}, so a template saved under an older set still gets a tile.
 */
final class ReceiptIcons
{
    /** The symbol of «Ohne Vorlage» and of every key without a glyph. */
    public const DEFAULT = 'receipt';

    /** key => SVG path data (24×24, stroke). Keys = `ReceiptTemplate::ICONS` (harness-checked). */
    public const GLYPHS = [
        'fuel'    => 'M4 20V5a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v15M3 20h11M4 10h9M13 8h2a2 2 0 0 1 2 2v6a1.5 1.5 0 0 0 3 0V9l-3-3',
        'office'  => 'M4 20l4-1 11-11-3-3L5 16l-1 4zM14 6l3 3',
        'meal'    => 'M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 3c-2 1-3 4-3 7h3v11',
        'parking' => 'M7 4h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3zM10 16V8h3a2.5 2.5 0 0 1 0 5h-3',
        'transit' => 'M9 3h6a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3zM6 10h12M9 16v4l-2 2M15 16v4l2 2',
        'mail'    => 'M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM3 7l9 6 9-6',
        'tools'   => 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z',
        'receipt' => 'M7 3h7l4 4v14H7zM14 3v4h4',
    ];

    /** The key a tile draws for a stored icon: itself when it has a glyph, else {@see DEFAULT}. */
    public static function resolve(string $key): string
    {
        return isset(self::GLYPHS[$key]) ? $key : self::DEFAULT;
    }
}
