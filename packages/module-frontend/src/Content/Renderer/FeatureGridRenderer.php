<?php

namespace Z77\Module\Frontend\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * Frontend feature grid.
 * { "type": "features", "eyebrow": "...", "title": "...",
 *   "items": [ { "number": "01", "title": "...", "text": "..." }, ... ] }
 * → <section class="fe-section"> with a <div class="fe-grid"> of <article class="fe-item">.
 */
final class FeatureGridRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'features';
    }

    public function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'kind' => 'text', 'label' => 'Eyebrow'],
            ['key' => 'title', 'kind' => 'text', 'label' => 'Titel'],
            ['key' => 'items', 'kind' => 'list', 'label' => 'Features', 'item' => [
                ['key' => 'number', 'kind' => 'text', 'label' => 'Nummer'],
                ['key' => 'title', 'kind' => 'text', 'label' => 'Titel'],
                ['key' => 'text', 'kind' => 'textarea', 'label' => 'Text'],
            ]],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $eyebrow = $inline->toHtml((string)($block['eyebrow'] ?? ''));
        $title   = $inline->toHtml((string)($block['title'] ?? ''));

        $items = '';
        foreach ((array)($block['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items .= '<article class="fe-item">'
                . '<div class="fe-item__number">'.$inline->toHtml((string)($item['number'] ?? '')).'</div>'
                . '<h3 class="fe-item__title">'.$inline->toHtml((string)($item['title'] ?? '')).'</h3>'
                . '<p class="fe-item__text">'.$inline->toHtml((string)($item['text'] ?? '')).'</p>'
                . '</article>';
        }

        return '<section class="fe-section"><div class="fe-container">'
            . '<div class="fe-section__eyebrow">'.$eyebrow.'</div>'
            . '<h2 class="fe-section__title">'.$title.'</h2>'
            . '<div class="fe-grid">'.$items.'</div>'
            . '</div></section>';
    }
}
