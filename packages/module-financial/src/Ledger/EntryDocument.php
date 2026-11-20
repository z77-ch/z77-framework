<?php

namespace Z77\Module\Financial\Ledger;

/**
 * The document behind a generated journal entry — the receipt photo, the supplier's PDF, the
 * issued invoice — as the OWNING module answers it ({@see EntryDocumentProviderInterface}):
 * an address the journal links to and a short German label. Financial knows neither the
 * document nor its store; the address is the owning module's gated delivery (module-dms'
 * read-gated preview, debtor's invoice PDF action), never a public path.
 *
 * Immutable. The address must be a same-origin PATH (`/backend/…`): a module bug must not put
 * a `javascript:` or a foreign URL into the journal's markup (Rule 7).
 */
final class EntryDocument
{
    public const LABEL_MAX = 60;

    public readonly string $url;
    public readonly string $label;

    /** @throws \InvalidArgumentException an address that is not a same-origin path, an empty or overlong label */
    public function __construct(string $url, string $label)
    {
        if (preg_match('#^/(?![/\\\\])[^\s<>"\']*$#', $url) !== 1) {
            throw new \InvalidArgumentException("EntryDocument: '{$url}' is not a same-origin path.");
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX) {
            throw new \InvalidArgumentException('EntryDocument: the label must be 1–' . self::LABEL_MAX . ' characters.');
        }
        $this->url   = $url;
        $this->label = $label;
    }
}
