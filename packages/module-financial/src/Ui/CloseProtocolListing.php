<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Repositories\CloseProtocolSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The protocol «Abschlüsse und MWST» as a standard list (listing.md; owner 2026-11-25, board S2-9):
 * the second tab of Finanzen › Änderungsprotokoll — the fiscal-year close / reopen protocol and the
 * VAT return protocol (post, lift) in ONE list, newest first, read only. Columns: the voucher slot
 * (the archived VAT return PDF of that revision — only when the list can have it, see
 * {@see definition()}) · Zeit · Wer · Was · Betrifft · Grund / Angaben; four of them searched in the
 * database, strictly parsed; 50 per page. The extra `all` is «Alle Jahre» of the year selection at
 * the top of the rail, as on the first tab.
 */
final class CloseProtocolListing
{
    public const ID = 'close-protocol-find';

    public const LIMIT = 50;

    /** German labels of the kinds — PRESENTATION ONLY; the set is {@see CloseProtocolSearch::KINDS}. */
    public const KIND_LABELS = [
        CloseProtocolSearch::KIND_VAT_POSTED    => 'MWST gebucht',
        CloseProtocolSearch::KIND_VAT_LIFTED    => 'MWST aufgehoben',
        CloseProtocolSearch::KIND_YEAR_CLOSED   => 'Jahr abgeschlossen',
        CloseProtocolSearch::KIND_YEAR_REOPENED => 'Jahr geöffnet',
    ];

    /** The badge of a kind — the existing pills (board S2-9: posted / closed green, lifted grey, reopened amber). */
    public const KIND_BADGES = [
        CloseProtocolSearch::KIND_VAT_POSTED    => 'badge--success',
        CloseProtocolSearch::KIND_VAT_LIFTED    => 'badge--muted',
        CloseProtocolSearch::KIND_YEAR_CLOSED   => 'badge--success',
        CloseProtocolSearch::KIND_YEAR_REOPENED => 'badge--warning',
    ];

    /**
     * @param bool $voucher whether a row can carry the voucher icon — the leading slot exists only
     *                      then (backend-screen.md §4: a column exists only when the list can have the icon)
     */
    public static function definition(bool $voucher = true): ListDefinition
    {
        return new ListDefinition(self::ID, array_merge($voucher ? [Column::slot('Beleg')] : [], [
            Column::search('f_at', 'Zeit', '8.5rem', 'at', Parsers::dateRange(), descendingFirst: true),
            Column::search('f_who', 'Wer', '7rem', null, Parsers::text(), priority: '3'),
            Column::search('f_what', 'Was', '9.5rem', null, self::kindParser()),
            Column::search('f_subject', 'Betrifft', 'minmax(9rem, 1fr)', null, Parsers::dateRange()),
            Column::plain('Grund / Angaben', 'minmax(10rem, 2fr)', priority: '2'),
        ]), 'at', self::LIMIT, ['all' => ['', '1']]);
    }

    /**
     * «Was»: the kinds whose German label contains the typed text, case-insensitive («MWST» → both
     * VAT kinds, «geöffnet» → the reopen). A text no label contains is INVALID — it does not narrow
     * the list (listing.md: a typo must not look like «nothing found»).
     */
    public static function kindParser(): \Closure
    {
        return static function (string $value): ?array {
            $needle = mb_strtolower(trim($value));
            $kinds  = array_keys(array_filter(self::KIND_LABELS, static fn(string $label): bool => str_contains(mb_strtolower($label), $needle)));

            return $needle === '' || $kinds === [] ? null : array_values($kinds);
        };
    }

    /** What the repository is asked for — scoped to $year unless «Alle Jahre» is on. */
    public static function search(ListState $state, ?FiscalYear $year): CloseProtocolSearch
    {
        $scoped  = !$state->flag('all') && $year !== null;
        $at      = $state->parsed('f_at');
        $subject = $state->parsed('f_subject');

        return new CloseProtocolSearch(
            $scoped ? $year->getId() : null,
            $scoped ? $year->getStartDate()->format('Y-m-d') : null,
            $scoped ? $year->getEndDate()->format('Y-m-d') : null,
            $at[0] ?? null,
            $at[1] ?? null,
            $state->parsed('f_who'),
            $state->parsed('f_what'),
            $subject[0] ?? null,
            $subject[1] ?? null,
            $state->descending,
        );
    }
}
