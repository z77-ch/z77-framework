<?php

namespace Z77\Module\Debtor\Services;

/**
 * The per-language document text of a master-data row (`HasDocumentText`:
 * payment terms, dunning levels) resolved for ONE document: the document's
 * language first, the installation's default language as the fallback
 * (`i18n.md` — the validator guarantees the default is filled whenever any
 * language is), an empty string when the row carries no text at all.
 *
 * The resolver part 1 left out on purpose — it arrives with the first
 * document that prints the text (the invoice's `termsText` snapshot).
 */
final class DocumentText
{
    /** @param array<string, string> $texts language code → text, as the entity stores it */
    public static function resolve(array $texts, string $language, string $defaultLanguage): string
    {
        $language        = mb_strtolower(trim($language));
        $defaultLanguage = mb_strtolower(trim($defaultLanguage));

        return trim((string) ($texts[$language] ?? $texts[$defaultLanguage] ?? ''));
    }
}
