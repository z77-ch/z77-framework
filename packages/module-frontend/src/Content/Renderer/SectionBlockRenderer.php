<?php

namespace Z77\Module\Frontend\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;
use Z77\Shared\Content\InlineProfile;

/**
 * Base of the frontend's section block types (text-section, cards, image-text,
 * cta, form, footer — the starter pages' building blocks, ADR-044 addendum
 * 2026-10-22).
 *
 * The starter pages are BESPOKE: the controller reads the page's content
 * document through ContentView::keyed() (blueprint slots) and hands each section
 * partial (`partials/sections/*`) its values; the partial owns the markup. A type
 * here therefore declares its fields (type() + schema()); render() is only the
 * stream fallback the BlockRenderer contract requires — it reads exactly the
 * schema fields (scalars as paragraphs, lists as list items, each under the
 * field's inline profile) inside a plain `fe-section`.
 */
abstract class SectionBlockRenderer implements BlockRenderer
{
    /** Formatting a running-text field allows: bold, italic, links (pages localised, external in a new tab). */
    protected const RUNNING_TEXT = [
        'inline' => ['bold', 'italic', 'link'],
        'links'  => ['targets' => ['page', 'media', 'external', 'mailto', 'tel'], 'localize' => true, 'newTab' => true],
    ];

    /** Plain single-line text. */
    protected static function text(string $key, string $label, int $max = 200, bool $required = false): array
    {
        return ['key' => $key, 'kind' => 'text', 'label' => $label, 'maxLength' => $max, 'required' => $required];
    }

    /** Plain multi-line text (no formatting). */
    protected static function textarea(string $key, string $label, int $max = 1200, bool $required = false): array
    {
        return ['key' => $key, 'kind' => 'textarea', 'label' => $label, 'maxLength' => $max, 'required' => $required];
    }

    /** Paragraphs of running text (one textarea per paragraph). */
    protected static function paragraphs(string $key, string $label, int $min = 0): array
    {
        return ['key' => $key, 'kind' => 'list', 'label' => $label, 'min' => $min, 'item' => 'textarea', 'maxLength' => 2000]
            + self::RUNNING_TEXT;
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $out = '';
        foreach ($this->schema() as $field) {
            $out .= $this->renderField($field, $block[$field['key']] ?? null, $inline);
        }

        return $out === '' ? '' : '<section class="fe-section"><div class="fe-container">' . $out . '</div></section>';
    }

    private function renderField(array $field, mixed $value, InlineMarkdown $inline): string
    {
        $kind = $field['kind'] ?? '';
        if ($kind === 'bool' || $kind === 'select' || $kind === 'url') {
            return '';   // presentation switches and targets, not text
        }

        $profile = InlineProfile::fromDescriptor($field);
        if ($kind === 'list') {
            $items = '';
            foreach (is_array($value) ? $value : [] as $item) {
                $items .= '<li>' . (is_array($item)
                    ? implode(' ', array_map(
                        static fn($v): string => htmlspecialchars(is_scalar($v) ? (string)$v : '', ENT_QUOTES, 'UTF-8'),
                        $item
                    ))
                    : $inline->toHtml((string)$item, $profile)) . '</li>';
            }
            return $items === '' ? '' : '<ul>' . $items . '</ul>';
        }

        return is_scalar($value) && (string)$value !== ''
            ? '<p>' . $inline->toHtml((string)$value, $profile) . '</p>'
            : '';
    }
}
