<?php

namespace Z77\Module\Frontend\Content\Renderer;

/**
 * Text section — optional overline, a heading and paragraphs of running text
 * (`partials/sections/textSection`; on Impressum / Datenschutz one section per
 * heading, `partials/sections/legalText`). A section whose heading and
 * paragraphs are all empty is not shown — that is how a page drops a heading it
 * does not need without a code change.
 */
final class TextSectionRenderer extends SectionBlockRenderer
{
    public function type(): string
    {
        return 'text-section';
    }

    public function schema(): array
    {
        return [
            self::text('eyebrow', 'Überzeile (optional)', 60),
            self::text('title', 'Titel', 160),
            self::paragraphs('paras', 'Absätze'),
        ];
    }
}
