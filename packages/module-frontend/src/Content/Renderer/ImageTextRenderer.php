<?php

namespace Z77\Module\Frontend\Content\Renderer;

/**
 * Image and text side by side — overline, heading, paragraphs and one image
 * (`partials/sections/imageText`). An empty image address shows the text alone.
 * `position` is a fixed choice mapped to a CSS modifier by the partial.
 */
final class ImageTextRenderer extends SectionBlockRenderer
{
    public function type(): string
    {
        return 'image-text';
    }

    public function schema(): array
    {
        return [
            self::text('eyebrow', 'Überzeile (optional)', 60),
            self::text('title', 'Titel', 160),
            self::paragraphs('paras', 'Absätze'),
            ['key' => 'image', 'kind' => 'url', 'label' => 'Bild (Adresse, z. B. /media/… oder /assets/…)', 'maxLength' => 500],
            self::text('alt', 'Bildbeschreibung (Alt-Text)', 200),
            ['key' => 'position', 'kind' => 'select', 'label' => 'Bild',
             'options' => ['' => 'rechts vom Text', 'left' => 'links vom Text'], 'default' => ''],
        ];
    }
}
