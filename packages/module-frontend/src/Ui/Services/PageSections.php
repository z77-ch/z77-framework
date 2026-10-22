<?php

namespace Z77\Module\Frontend\Ui\Services;

use Z77\Shared\Content\BlockView;
use Z77\Shared\Content\ContentView;

/**
 * Content slot → section partial data, one shape per section partial
 * (`partials/sections/*`) — the starter pages' building blocks (ADR-044
 * addendum 2026-10-22).
 *
 * A controller reads its page document (PageContent) and passes each slot
 * through the method of its block type; the partial gets plain values (escape
 * with e()), formatted running text as `…Html` (already safe: the field's inline
 * profile, ContentView) and `edit`, the page-editor marker of the slot (ADR-045
 * §4, '' for visitors), which it prints on its root element. Reusable by any
 * controller that renders these block types — nothing here knows a page.
 */
final class PageSections
{
    private function __construct() {}

    /** `hero` → partials/sections/hero */
    public static function hero(ContentView $page, string $key): array
    {
        $block = $page->keyed($key);

        return [
            'eyebrow' => (string)$block->get('eyebrow'),
            'title'   => (string)$block->get('title'),
            'subline' => (string)$block->get('subline'),
            'edit'    => $page->editAttribute($key),
        ];
    }

    /** `text-section` → partials/sections/textSection (and one entry of legalText) */
    public static function textSection(ContentView $page, string $key): array
    {
        $block = $page->keyed($key);

        return [
            'eyebrow'   => (string)$block->get('eyebrow'),
            'title'     => (string)$block->get('title'),
            'parasHtml' => array_values(array_filter($block->listHtml('paras'), static fn(string $p): bool => trim($p) !== '')),
            'edit'      => $page->editAttribute($key),
        ];
    }

    /**
     * Several `text-section` slots in order, without the ones left empty (no
     * heading, no paragraph) — how a legal page drops a heading it does not need.
     *
     * @param list<string> $keys
     * @return list<array>
     */
    public static function textSections(ContentView $page, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $section = self::textSection($page, $key);
            if ($section['title'] !== '' || $section['parasHtml'] !== []) {
                $out[] = $section;
            }
        }

        return $out;
    }

    /** `cards` → partials/sections/cards */
    public static function cards(ContentView $page, string $key): array
    {
        $block = $page->keyed($key);
        $items = [];
        foreach ($block->list('items') as $item) {
            if ($item instanceof BlockView) {
                $items[] = ['title' => (string)$item->get('title'), 'text' => (string)$item->get('text')];
            }
        }

        return [
            'eyebrow' => (string)$block->get('eyebrow'),
            'title'   => (string)$block->get('title'),
            'items'   => $items,
            'edit'    => $page->editAttribute($key),
        ];
    }

    /** `image-text` → partials/sections/imageText; `image` is '' when absent or not a safe address. */
    public static function imageText(ContentView $page, string $key): array
    {
        $block = $page->keyed($key);

        return [
            'eyebrow'   => (string)$block->get('eyebrow'),
            'title'     => (string)$block->get('title'),
            'parasHtml' => array_values(array_filter($block->listHtml('paras'), static fn(string $p): bool => trim($p) !== '')),
            'image'     => self::safeSrc((string)$block->get('image')),
            'alt'       => (string)$block->get('alt'),
            // Fixed choice → fixed modifier; anything else is the default (image right).
            'imageLeft' => $block->get('position') === 'left',
            'edit'      => $page->editAttribute($key),
        ];
    }

    /** `cta` → partials/sections/cta; a page address is localised, '' when not a safe target. */
    public static function cta(ContentView $page, string $key): array
    {
        $block = $page->keyed($key);

        return [
            'title' => (string)$block->get('title'),
            'text'  => (string)$block->get('text'),
            'label' => (string)$block->get('label'),
            'href'  => self::target((string)$block->get('href')),
            'edit'  => $page->editAttribute($key),
        ];
    }

    /** `form` → partials/sections/form (the text above the form; the form is the controller's). */
    public static function form(ContentView $page, string $key): array
    {
        $block = $page->keyed($key);

        return [
            'eyebrow' => (string)$block->get('eyebrow'),
            'title'   => (string)$block->get('title'),
            'text'    => (string)$block->get('text'),
            'edit'    => $page->editAttribute($key),
        ];
    }

    /**
     * Link target of a button: a page of this site (`/contact`, canonical, the
     * same in every language) through localizedUrl() — which also carries a
     * content preview —, an anchor or an http(s)/mailto/tel address unchanged,
     * anything else ''. The backend validates on save; this is the render-side
     * gate for documents written another way (file edit, import).
     */
    private static function target(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '//')) {
            return '';
        }
        if ($href[0] === '/') {
            return localizedUrl($href);
        }
        if ($href[0] === '#') {
            return $href;
        }

        return preg_match('~^(https?://|mailto:|tel:)~i', $href) === 1 ? $href : '';
    }

    /** Image address: site-relative (not //) or http(s), otherwise ''. */
    private static function safeSrc(string $src): string
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, '//')) {
            return '';
        }

        return $src[0] === '/' || preg_match('~^https?://~i', $src) === 1 ? $src : '';
    }
}
