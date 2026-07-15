<?php

namespace Z77\Module\Frontend\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * Frontend prose section (optional dark variant).
 * { "type": "prose-section", "variant": "dark"?, "eyebrow": "...", "title": "...", "lead": "..." }
 * → <section class="fe-section [fe-section--dark]"> with eyebrow + title + lead.
 */
final class ProseSectionRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'prose-section';
    }

    public function schema(): array
    {
        return [
            ['key' => 'variant', 'kind' => 'select', 'label' => 'Variante',
             'options' => ['' => 'Standard', 'dark' => 'Dunkel'], 'default' => ''],
            ['key' => 'eyebrow', 'kind' => 'text', 'label' => 'Eyebrow'],
            ['key' => 'title', 'kind' => 'text', 'label' => 'Titel'],
            ['key' => 'lead', 'kind' => 'textarea', 'label' => 'Lead'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $modifier = ($block['variant'] ?? '') === 'dark' ? ' fe-section--dark' : '';
        $eyebrow  = $inline->toHtml((string)($block['eyebrow'] ?? ''));
        $title    = $inline->toHtml((string)($block['title'] ?? ''));
        $lead     = $inline->toHtml((string)($block['lead'] ?? ''));

        return '<section class="fe-section'.$modifier.'"><div class="fe-container">'
            . '<div class="fe-section__eyebrow">'.$eyebrow.'</div>'
            . '<h2 class="fe-section__title">'.$title.'</h2>'
            . '<p class="fe-section__lead">'.$lead.'</p>'
            . '</div></section>';
    }
}
