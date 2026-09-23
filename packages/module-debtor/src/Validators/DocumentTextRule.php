<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Core\DI;

/**
 * The one rule for per-language master-data text that PRINTS on a customer
 * document — the payment terms' text on an invoice, the dunning level's on
 * a notice. Both follow `i18n.md`, so the rule lives once (Rule 8).
 *
 * Two conditions, and both exist because the document resolves its text in
 * part 2 with the content fallback (`ContentService::find()`: the requested
 * language, else `defaultLanguage`):
 *
 *   1. every key must be a language of THIS installation
 *      (`I18n::isValidLanguage()` — the same whitelist the URL segment is
 *      validated against). A text under `it` in a de/fr installation would
 *      never be reachable;
 *   2. as soon as ANY language is filled, the DEFAULT language must be too —
 *      it is what every other language falls back to, and a text only in
 *      `fr` would print nothing at all for a German customer.
 *
 * Leaving the text empty everywhere stays allowed: not every terms row needs
 * a sentence on the document.
 *
 * @param array<string, string> $texts language code → text (already trimmed by the entity)
 * @return string|null the German field error, or null when the texts pass
 */
final class DocumentTextRule
{
    /** The field the error is reported on — the form renders one textarea per language under it. */
    public const FIELD = 'document_text';

    public static function check(array $texts): ?string
    {
        $i18n = DI::getI18n();

        foreach (array_keys($texts) as $language) {
            if (!$i18n->isValidLanguage($language)) {
                return 'Sprache «' . $language . '» ist keine Sprache dieser Installation.';
            }
        }

        $default = $i18n->getDefaultLanguage();
        if ($texts !== [] && trim($texts[$default] ?? '') === '') {
            return 'Der Text in der Standardsprache «' . mb_strtoupper($default)
                . '» fehlt — er ist der Rückfall für jede andere Sprache.';
        }

        return null;
    }
}
