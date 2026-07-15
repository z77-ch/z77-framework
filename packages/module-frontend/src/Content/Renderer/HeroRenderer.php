<?php

namespace Z77\Module\Frontend\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * Frontend hero block.
 * { "type": "hero", "eyebrow": "...", "title": "...", "subline": "..." }
 * → the frontend's <section class="fe-hero"> markup.
 */
final class HeroRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'hero';
    }

    public function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'kind' => 'text', 'label' => 'Eyebrow'],
            ['key' => 'title', 'kind' => 'text', 'label' => 'Titel'],
            ['key' => 'subline', 'kind' => 'textarea', 'label' => 'Subline'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $eyebrow = $inline->toHtml((string)($block['eyebrow'] ?? ''));
        $title   = $inline->toHtml((string)($block['title'] ?? ''));
        $subline = $inline->toHtml((string)($block['subline'] ?? ''));

        return '<section class="fe-hero"><div class="fe-container">'
            . '<div class="fe-hero__eyebrow">'.$eyebrow.'</div>'
            . '<h1 class="fe-hero__title">'.$title.'</h1>'
            . '<p class="fe-hero__subline">'.$subline.'</p>'
            . '</div></section>';
    }
}
