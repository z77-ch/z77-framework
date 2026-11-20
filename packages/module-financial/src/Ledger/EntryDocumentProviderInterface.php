<?php

namespace Z77\Module\Financial\Ledger;

/**
 * The port by which an OWNING module says which document stands behind the journal entries it
 * posted — a photo, a supplier's PDF, an issued invoice. The journal screen shows an icon and a
 * link; it knows an entry only by its opaque origin (`source_type` / `source_ref`, ADR-040
 * decision 2) — the module that wrote the origin is the one that can resolve it (Rule 9:
 * financial names no consumer).
 *
 * Declared by class name under the module config key `entryDocuments` ({@see EntryDocuments});
 * instantiated without arguments, reaching its services through DI.
 *
 * ```php
 * // the owning module's config: <module>Config.inc.php
 * 'entryDocuments' => [OwnEntryDocuments::class],
 * ```
 */
interface EntryDocumentProviderInterface
{
    /**
     * The document of each entry this module posted that the CURRENT SESSION may open — an entry of
     * another origin, without a document, or whose document the session may not read (the DMS
     * ACL, the route's role) is left out. Read-only; called once per journal page with all its
     * generated entries, so answer the whole list in few queries (no query per entry).
     *
     * @param list<array{id: int, sourceType: string, sourceRef: string}> $entries generated journal entries
     * @return array<int, EntryDocument> journal entry id → its document
     */
    public function documentsFor(array $entries): array;
}
