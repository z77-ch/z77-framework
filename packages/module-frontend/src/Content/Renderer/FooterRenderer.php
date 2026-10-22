<?php

namespace Z77\Module\Frontend\Content\Renderer;

/**
 * Footer texts of every page (`partials/footer`, data from Ui\Services\FooterContent).
 * The brand mark is not content (`Z77\Shared` partials/brandMark), the link
 * columns come from the navigation, the year from the clock.
 */
final class FooterRenderer extends SectionBlockRenderer
{
    public function type(): string
    {
        return 'footer';
    }

    public function schema(): array
    {
        return [
            self::text('tagline', 'Kurzbeschreibung unter dem Namen', 160),
            self::text('copyright', 'Copyright-Inhaber (nach «© Jahr»)', 120),
        ];
    }
}
