<?php

namespace Z77\Module\Frontend\Content\Renderer;

/**
 * Form — the text above a public form (`partials/sections/form`). Only the text
 * is content: the fields, their labels and the sending are code (the form's
 * FormDefinition, e.g. ContactFormDefinition, rendered by `partials/publicForm`).
 */
final class FormRenderer extends SectionBlockRenderer
{
    public function type(): string
    {
        return 'form';
    }

    public function schema(): array
    {
        return [
            self::text('eyebrow', 'Überzeile (optional)', 60),
            self::text('title', 'Titel', 160),
            self::textarea('text', 'Text', 600),
        ];
    }
}
