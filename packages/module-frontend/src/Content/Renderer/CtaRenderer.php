<?php

namespace Z77\Module\Frontend\Content\Renderer;

/**
 * Call to action — heading, one sentence and a button (`partials/sections/cta`).
 * `href` is a page of this site as its canonical address (`/contact`, the same
 * in every language — the controller localises it), or an external address.
 */
final class CtaRenderer extends SectionBlockRenderer
{
    public function type(): string
    {
        return 'cta';
    }

    public function schema(): array
    {
        return [
            self::text('title', 'Titel', 160),
            self::textarea('text', 'Text', 400),
            self::text('label', 'Button-Text', 60, true),
            ['key' => 'href', 'kind' => 'url', 'label' => 'Button-Ziel (z. B. /contact)', 'maxLength' => 500, 'required' => true],
        ];
    }
}
