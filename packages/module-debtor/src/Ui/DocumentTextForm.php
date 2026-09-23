<?php

namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI;

/**
 * The per-language text block of a master-data form — one textarea per
 * language of this installation, posted as `document_text[de]`,
 * `document_text[fr]`, … (the bracket notation `core.js` collects into a
 * map). Payment terms and dunning levels both carry such a text, so the
 * form side lives once (Rule 8); the RULE it must satisfy lives once too,
 * in {@see \Z77\Module\Debtor\Validators\DocumentTextRule}.
 *
 * The template partial `Backend/_documentText` renders the fields from
 * these two methods.
 */
final class DocumentTextForm
{
    /** The languages a document text is kept in — the installation's (`i18n.md`), default first. */
    public static function languages(): array
    {
        $i18n      = DI::getI18n();
        $default   = $i18n->getDefaultLanguage();
        $languages = $i18n->getLanguages();

        return array_values(array_unique([$default, ...$languages]));
    }

    /**
     * The posted texts, restricted to those languages — a field for a
     * language the site does not serve is ignored rather than stored and
     * then refused.
     *
     * @param list<string> $languages
     * @return array<string, string>
     */
    public static function posted(array $body, array $languages): array
    {
        $posted = is_array($body['document_text'] ?? null) ? $body['document_text'] : [];
        $texts  = [];
        foreach ($languages as $language) {
            $texts[$language] = trim((string) ($posted[$language] ?? ''));
        }

        return $texts;
    }
}
