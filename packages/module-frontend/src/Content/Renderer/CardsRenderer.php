<?php

namespace Z77\Module\Frontend\Content\Renderer;

/**
 * Cards — optional overline and heading over a grid of one to six cards, each a
 * title and a short text (`partials/sections/cards`). The cards are numbered by
 * the partial; the number is not content.
 */
final class CardsRenderer extends SectionBlockRenderer
{
    public function type(): string
    {
        return 'cards';
    }

    public function schema(): array
    {
        return [
            self::text('eyebrow', 'Überzeile (optional)', 60),
            self::text('title', 'Titel (optional)', 160),
            ['key' => 'items', 'kind' => 'list', 'label' => 'Karten', 'min' => 1, 'max' => 6, 'item' => [
                self::text('title', 'Titel', 80, true),
                self::textarea('text', 'Text', 400),
            ]],
        ];
    }
}
