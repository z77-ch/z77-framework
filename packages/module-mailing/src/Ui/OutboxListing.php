<?php
namespace Z77\Module\Mailing\Ui;

use Z77\Module\Mailing\Repositories\OutboxSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * Service › Postausgang as a standard list (listing.md): one row per outbox
 * mail. Columns: the state icon (opens the mail as a window) · ⋮ (the row's
 * context menu) · Erstellt · Empfänger · Betreff · Quelle · Status · Versuche
 * · Fehler — four of them searched in the database, strictly parsed; newest
 * first, 50 per page. The extra `view` is the toolbar's tabs (Wartend ·
 * Gesendet · Fehlgeschlagen · Alle, {@see OutboxSearch::VIEWS}); the extra
 * `batch` (a PATTERN extra, listing.md — owner 2026-10-23) narrows the list to
 * one send run: the link «Postausgang ansehen» of a send action
 * ({@see batchUrl()}), shown as the chip «Versand #…» with its ×.
 */
final class OutboxListing
{
    public const ID = 'outbox-find';

    public const LIMIT = 50;

    /** Where the backend mounts the screen (`Service/OutboxController`) — the trait's default base, the links of other modules. */
    public const MOUNT = '/backend/service/outbox';

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('E-Mail anzeigen'),
            Column::slot('Aktionen', '1.6rem'),
            Column::search('f_created', 'Erstellt', '8.5rem', 'created', Parsers::dateRange(), descendingFirst: true),
            Column::search('f_recipient', 'Empfänger', 'minmax(10rem, 1.5fr)', 'recipient', Parsers::text()),
            Column::search('f_subject', 'Betreff', 'minmax(10rem, 2fr)', 'subject', Parsers::text()),
            Column::search('f_source', 'Quelle', '8rem', 'source', Parsers::text(), priority: '3'),
            Column::plain('Status', '7.5rem'),
            Column::plain('Versuche', '4.5rem', numeric: true, priority: '2'),
            Column::plain('Fehler', 'minmax(8rem, 1fr)', priority: '3'),
        ], 'created', self::LIMIT, ['view' => OutboxSearch::VIEWS, 'batch' => OutboxSearch::BATCH_PATTERN]);
    }

    /**
     * The list of ONE send run, every state («Alle») — what a send action links as «Postausgang
     * ansehen» (module-agnostic: debtor's documents, later the newsletter).
     */
    public static function batchUrl(string $batch): string
    {
        return self::MOUNT . '/list?' . http_build_query(['view' => 'all', 'batch' => $batch]);
    }

    /** The short form of a batch id the chip shows — «Versand #3f2a9c01». */
    public static function batchLabel(string $batch): string
    {
        return 'Versand #' . substr($batch, 0, 8);
    }

    /** What the repository is asked for. */
    public static function search(ListState $state): OutboxSearch
    {
        $created = $state->parsed('f_created');

        return new OutboxSearch(
            $state->extra('view'),
            $created[0] ?? null,
            $created[1] ?? null,
            $state->parsed('f_recipient'),
            $state->parsed('f_subject'),
            $state->parsed('f_source'),
            $state->sort,
            $state->descending,
            $state->extra('batch'),
        );
    }
}
