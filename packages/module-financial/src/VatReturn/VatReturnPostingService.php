<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnCorrection;
use Z77\Module\Financial\Entities\VatReturnFigure;
use Z77\Module\Financial\Entities\VatReturnLog;
use Z77\Module\Financial\Entities\VatReturnLogAction;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Pdf\VatReturnArchive;
use Z77\Module\Financial\Pdf\VatReturnPdf;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\IdempotencyConflictException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\ReversalRefusedException;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FieldAssignment;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Interface\TransactionInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «MWST buchen» — the WRITER of the VAT return (stage S2 slice 5a; ADR-042 addendum
 * 2026-11-24 points 1, 2, 5, 6; ADR-041 addendum points 1, 2, 5, 13, 14; the rules as built:
 * financial.md, mental model «MWST buchen, the service layer» and «lifting a posted return»). The
 * service layer only: NO controller action, button or dialog calls it yet (the boards are not
 * decided) — a deploy shows nothing new.
 *
 *   - {@see plan()} — lock-free: what «MWST buchen» would post for one period
 *     ({@see VatSettlementPlan}: the form, the findings with the posting preconditions, the
 *     settlement entry, the two fingerprints). What a confirm dialog shows and hands back.
 *   - {@see post()} — posts it: owns its unit of work, takes the locks below, computes the plan
 *     AGAIN on a fresh {@see VatReturnService} after them and refuses when it is blocked or differs
 *     from what was confirmed; then the settlement entry through `LedgerService::post()`, the
 *     voucher into {@see VatReturnArchive}, the {@see VatReturn} row with one
 *     {@see VatReturnFigure} per form field and one {@see VatReturnLog} row `post`. Posted means
 *     filed (owner 2026-11-25): the user files in the ESTV ePortal first, then posts here — there
 *     is no filed date, no filed state.
 *   - {@see lift()} — «Aufheben» (slice 6a): reverses the settlement entry of the LATEST live
 *     return through the ledger, marks the row lifted, logs `lift`; the period is open again and
 *     the next {@see post()} writes the next revision.
 *
 * LOCK ORDER of {@see post()} and {@see lift()} — steps 0–4 in ONE place, {@see takeWriterLocks()}
 * (financial.md «MWST buchen»; every step a LOCKING read, so the unit of work's REPEATABLE READ
 * snapshot starts only at step 6 — financial.md «the freeze», «Two checks per write»):
 *
 *   0. the writer row `NumberRangeRepository::lock('vat-return')` (X) — EVERY return writer takes
 *      it first (post / lift); postings never do. The row is seeded by
 *      the migration `Version20261125100000`, so the first lock never takes the creation path of
 *      DOCTRINE-NR-003;
 *   1. the journal range of the fiscal year holding the period's last day (X) — serialises against
 *      every `post()` of that year at ITS first lock;
 *   2. the accounts the entry posts on (S) — for post() the mandator's VAT accounts of
 *      `VatReturnService::vatAccountCategories()` and the settlement / rounding account; their
 *      postable / active flags are read under the lock and decide `account-missing` at step 6;
 *   3. EVERY fiscal-year row (S) — no year opened, deleted or closed under the return;
 *   4. the period rows of every fiscal year the VAT period touches (X, refreshed) — waits for every
 *      posting / edit / amend / retract in flight there (they hold the period S), in the earlier
 *      fiscal year of a spanning period too (step 1 locks the later year's range only);
 *   5. the latest live `vat_return` row (X);
 *   6. the first plain read: the mandator RE-READ (`CurrentMandator::fresh()` — the identity map's
 *      copy, which any earlier read of the request may have left, is refreshed), then a FRESH
 *      `VatReturnService` computes the plan;
 *   7. `LedgerService::post()` re-takes 1–4 without waiting.
 *
 * Steps 1 and 2 need the year's code and the account numbers BEFORE the unit of work — read
 * lock-free before it opens (the year code as a scalar, `FiscalYearRepository::codeOn()`); a year
 * that appeared or vanished meanwhile is refused (`fiscal-year-changed`, post again). A lock
 * conflict surfaces as the driver's exception (no retry loop, ADR-039 decision 10).
 *
 * THE BLOCK of correction entries (ADR-042 addendum 2 of 2026-11-25, points 1 and 2): the plan
 * carries `VatReturnService::correctionBlocks()` of the period; the settlement entry clears the
 * block's tax from the VAT accounts onto the settlement account as lines of their own — one per
 * corrected period and account, text «Korrektur {Periode}» (role {@see ROLE_CORRECTION}) — beside
 * the ordinary lines; `post()` stores the block (`VatReturnCorrection`, one row per corrected
 * period and field) and names it in the protocol row. `lift()` is refused while a correction entry
 * corrects the period of the return to lift; lifting a CARRYING return reverses its block lines with
 * the entry and keeps its stored rows.
 *
 * Every refusal is a {@see VatReturnRefusedException} with a stable reason and ONE German sentence
 * (`VatReturnRefusal`); nothing is written then — no entry, no row, no file, the number goes back.
 * A refusal of the LEDGER at step 7 (`PostingRefusedException`, `ReversalRefusedException`) comes
 * out as `ledger-refused` too, never as the ledger's English exception.
 */
final class VatReturnPostingService
{
    /**
     * The writer-serialisation row every return writer locks first (financial.md «MWST buchen», step
     * 0) — a `number_range` row that never hands out a number, seeded by `Version20261125100000`.
     */
    public const WRITER_LOCK = 'vat-return';

    /** The posting preconditions — a plan's blocking findings whose code IS the refusal reason of {@see post()}, in the order post() refuses. */
    public const PRECONDITIONS = [
        VatReturnRefusal::NOT_ELAPSED,
        VatReturnRefusal::NOT_NEXT,
        VatReturnRefusal::NO_FISCAL_YEAR,
        VatReturnRefusal::YEAR_CLOSED,
        VatReturnRefusal::ACCOUNT_MISSING,
    ];

    /** Line roles of the settlement entry, in posting order. */
    public const ROLE_OWED       = 'owed';
    public const ROLE_INPUT      = 'input';
    public const ROLE_SETTLEMENT = 'settlement';
    public const ROLE_ROUNDING   = 'rounding';
    /** A line of the block of correction entries — on a VAT account and on the settlement account, one per corrected period. */
    public const ROLE_CORRECTION = 'correction';

    /**
     * `lift()` refused: a correction entry corrects the period of the return to lift (ADR-042
     * addendum 2 of 2026-11-25, point 2) — it would correct an open period. A refusal reason of its
     * own, beside `VatReturnRefusal`'s.
     */
    public const REFUSAL_CORRECTED = 'corrected-by-entry';

    private const LINE_TEXTS = [
        self::ROLE_OWED       => 'Geschuldete MWST',
        self::ROLE_INPUT      => 'Vorsteuer',
        self::ROLE_SETTLEMENT => 'MWST-Zahllast',
        self::ROLE_ROUNDING   => 'Rundungsdifferenz MWST',
    ];

    /**
     * @param string|null           $actor    the name stamped on the return, the entry and the protocol; null = the session identity ({@see Actor::current()})
     * @param OpenWorkChecks|null   $checks   the open-work registry; null = every registered module's
     * @param VatSettings|null      $settings the mandator's VAT settings; null = read from the record (a harness passes its own)
     * @param VatReturnSources|null $sources  the origin declarations; null = every registered module's
     * @param string|null           $basePath the installation root of the voucher archive; null = `ABS_BASE_PATH`
     */
    public function __construct(
        private readonly UnifiedEntityManager $em,
        private readonly ?string $actor = null,
        private readonly ?OpenWorkChecks $checks = null,
        private readonly ?VatSettings $settings = null,
        private readonly ?VatReturnSources $sources = null,
        private readonly ?string $basePath = null,
    ) {}

    // ── the plan ─────────────────────────────────────────────────────────

    /**
     * What «MWST buchen» would post for $period — LOCK-FREE, nothing written. The findings carry
     * every reason the period cannot be posted now (blocking) and the warnings to confirm;
     * {@see post()} decides again under its locks.
     *
     * @throws VatReturnRefusedException the settings gate, `not-a-period`, `already-settled`, `period-overlaps`, or the period cannot be computed (no form, reverse charge, an unmapped or unknown code)
     */
    public function plan(VatPeriod $period, ?\DateTimeImmutable $today = null): VatSettlementPlan
    {
        $today = self::day($today);

        return $this->planWith($this->service(), $period, $today, $this->lastDayState($period));
    }

    // ── posting ──────────────────────────────────────────────────────────

    /**
     * Posts the return of $period (see the class docblock for the steps and the lock order).
     *
     * @param string                  $figuresFingerprint  the plan's {@see VatSettlementPlan::$figuresFingerprint} the poster saw
     * @param string|null             $warningsFingerprint the plan's warnings fingerprint the poster confirmed; null = none confirmed
     * @param \DateTimeImmutable|null $today               the day «elapsed» is judged on; null = today
     * @throws VatReturnRefusedException see `VatReturnRefusal` — the period, the preconditions, `blocked`, the warnings, `figures-changed`, `fiscal-year-changed`, `ledger-refused`
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function post(VatPeriod $period, string $figuresFingerprint, ?string $warningsFingerprint, ?\DateTimeImmutable $today = null): VatReturn
    {
        $today       = self::day($today);
        $transaction = $this->ownUnitOfWork('post()');
        $actor       = $this->actorName();

        // Before the unit of work, lock-free: which range and which accounts steps 1 and 2 lock. The year as a
        // scalar; the account numbers through the mandator's one reader — its entity is RE-READ at step 6.
        $yearCode = $this->years()->codeOn($period->to);
        $accounts = $this->settlementAccountNumbers();
        $archive  = new VatReturnArchive($this->basePath);

        try {
            return $transaction->run(function () use ($period, $figuresFingerprint, $warningsFingerprint, $today, $actor, $yearCode, $accounts, $archive): VatReturn {
                $locked = $this->takeWriterLocks($yearCode, $accounts, $period);                     // 0–4
                if ($locked['yearCode'] !== $yearCode) {
                    throw self::refused(VatReturnRefusal::FISCAL_YEAR_CHANGED, 'Die Geschäftsjahre wurden eben geändert — «MWST buchen» bitte nochmals auslösen.');
                }
                $this->returns()->lockLatestLive();                                                  // 5

                $this->refreshMandator();                                                            // 6 — the first plain read
                $plan = $this->planWith($this->service(), $period, $today, $locked, $locked['flags']);
                $this->assertPostable($plan, $figuresFingerprint, $warningsFingerprint);

                try {
                    return $this->write($plan, $actor, $archive);                                    // 7
                } catch (PostingRefusedException | ReversalRefusedException $e) {
                    throw $this->ledgerRefused($e, $period, false);
                }
            });
        } catch (\Throwable $e) {
            $archive->discardWritten();   // no file may lie there that no row names
            throw $e;
        }
    }

    // ── lifting ──────────────────────────────────────────────────────────

    /**
     * Lifts the posted return $returnId — «Aufheben» (ADR-042 addendum 2026-11-24
     * point 5; financial.md «lifting a posted return»): the emergency brake — admin only, the
     * latest return only, with a mandatory reason. Posted means filed (owner 2026-11-25); an error
     * in a FILED return is reported to the tax office (a correction), z77 does not reopen it for
     * that. Only the LATEST live return — the chain keeps no gap; an earlier one is lifted after
     * the later ones, latest first. The screen hands back the version it showed and the mandatory
     * reason. WHO may lift (the admin) is the screen's pin — the service itself knows no roles.
     *
     *   1. The settlement entry is reversed through the ledger's own reversal
     *      (`LedgerService::reverse()`: key `reversal:{year}/{number}`, origin inherited,
     *      `reversalOf` set), dated the settlement entry's own date — the period's last day: the
     *      VAT accounts show the period's coded tax again as of its end, every later period stays
     *      untouched. Text «Aufgehoben: » + the entry's text + « — {reason}», cut to the entry
     *      text's length (the whole reason stays on the row and in the protocol). Never deleted,
     *      never edited: entry and reversal both stay in the journal. A NIL return has no entry
     *      and writes no reversal.
     *   2. The row → `lifted` (`VatReturn::lift()`): who, when, why, the reversal; `live` released
     *      — the period is no longer settled, takes tax lines again, and «MWST buchen» posts it
     *      anew as the next revision (key `…:r{n}`, « (Neuerstellung n)», a voucher of its own).
     *      The figures and the archived voucher stay — the PDF is write-once and not touched
     *      (whether the lifted voucher gets a mark is an open owner question; «untouched» is what
     *      this does by doing nothing — financial.md «lifting a posted return»).
     *   3. One protocol row `lift` with the reason (detail: the entry and its reversal).
     *
     * LOCK ORDER — the writer's, as in {@see post()} ({@see takeWriterLocks()}): 0 the writer
     * row; 1 the journal range of the year the reversal goes into (the settlement entry's);
     * 2 the accounts of the settlement entry's lines (S — exactly the rows the reversal posts
     * on); 3 every fiscal-year row (S); 4 the period rows of every year the VAT period touches
     * (X, refreshed); 5 the latest live return, then the one asked for (X); 6 the first
     * plain read; 7 `LedgerService::reverse()` re-takes 1–4 without waiting. The period, the year
     * code and the account numbers come from the return and its settlement entry, read lock-free
     * as SCALARS before the unit of work (`VatReturnRepository::writerTargetOf()` — no entity is
     * left in the identity map) — an entry's year and lines never change.
     *
     * The year of the settlement entry must be open — checked on the locked period row of the
     * period's last day. An EARLIER year of a spanning period may be closed (the reversal goes
     * into the later year, as the entry did — G14). A nil return is refused the same way
     * (ADR-042 addendum point 5: «the year must be open»).
     *
     * Refused while a correction entry corrects the period ({@see REFUSAL_CORRECTED}, after
     * `not-latest`): the entry is changed or deleted first. A CARRYING return's block lines are
     * reversed with its settlement entry; its stored block rows stay, and its correction entries
     * return to the block of the next revision.
     *
     * @throws VatReturnRefusedException `no-reason`, `reason-too-long`, `not-found`, `already-lifted`, `stale`, `not-latest`, `corrected-by-entry`, `year-closed`, `ledger-refused` (the reversal refused — an account of the entry turned into a group)
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function lift(int $returnId, int $version, string $reason): VatReturn
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw self::refused(VatReturnRefusal::NO_REASON, 'Eine MWST-Abrechnung wird nur mit einem Grund aufgehoben — den Grund angeben.');
        }
        if (mb_strlen($reason) > VatReturn::REASON_LENGTH) {
            throw self::refused(VatReturnRefusal::REASON_TOO_LONG, 'Der Grund ist länger als ' . VatReturn::REASON_LENGTH . ' Zeichen — das Protokoll hält ihn ganz oder gar nicht; kürzer fassen.');
        }
        $transaction = $this->ownUnitOfWork('lift()');
        $actor       = $this->actorName();

        // Before the unit of work, lock-free and as scalars: the period, and which range and which accounts steps 1 and 2 lock.
        $seen = $this->returns()->writerTargetOf($returnId);
        if ($seen === null) {
            throw self::notFound();
        }
        $seenPeriod = VatPeriod::stored(new \DateTimeImmutable($seen['period_from']), new \DateTimeImmutable($seen['period_to']), VatRhythm::from($seen['rhythm']));

        return $transaction->run(function () use ($returnId, $version, $reason, $actor, $seen, $seenPeriod): VatReturn {
            $locked   = $this->takeWriterLocks($seen['year_code'], $seen['accounts'], $seenPeriod);   // 0–4
            $latestId = $this->returns()->lockLatestLive();                              // 5
            $current  = $this->returns()->lockedVersion($returnId);

            $return = $current === null ? null : $this->returns()->fresh($returnId);     // 6 — the first plain read
            if ($return === null) {
                throw self::notFound();
            }
            $period = $return->period();
            if (!$return->isLive()) {
                throw self::refused(VatReturnRefusal::ALREADY_LIFTED, 'Die MWST-Abrechnung ' . $period->label() . ' ist bereits aufgehoben (am '
                    . $return->getLiftedAt()?->format('d.m.Y') . ' von ' . $return->getLiftedBy() . ').');
            }
            if ($current !== $version) {
                throw self::refused(VatReturnRefusal::STALE, 'Die MWST-Abrechnung wurde inzwischen geändert — die Seite neu laden und nochmals versuchen.');
            }
            if ($latestId !== $returnId) {
                $latest = $latestId === null ? null : $this->returns()->fresh($latestId);
                throw self::refused(VatReturnRefusal::NOT_LATEST, 'Aufgehoben wird nur die neueste MWST-Abrechnung — zuerst ' . ($latest === null ? 'die späteren' : 'die Abrechnung ' . $latest->period()->label())
                    . ' aufheben, dann diese (' . $period->label() . ').', $latest === null ? '' : 'vat-return:' . $latest->getPeriodFrom()->format('Y-m-d'));
            }
            // A correction entry corrects THIS period — after the lift it would correct an open one (ADR-042 addendum 2
            // of 2026-11-25, point 2). A LOCKING read after the row locks: an entry committed meanwhile is seen.
            $correcting = $this->journalLines()->entriesCorrecting($period->from->format('Y-m-d'), true);
            if ($correcting !== []) {
                $first = $correcting[0]['year'] . '/' . $correcting[0]['number'];
                $more  = count($correcting) - 1;
                throw self::refused(self::REFUSAL_CORRECTED, $more === 0
                    ? 'Die Korrekturbuchung ' . $first . ' korrigiert ' . $period->label() . ' — zuerst diese Buchung ändern oder löschen, dann die Abrechnung aufheben.'
                    : 'Die Korrekturbuchung ' . $first . ' und ' . $more . ' weitere korrigieren ' . $period->label() . ' — zuerst diese Buchungen ändern oder löschen, dann die Abrechnung aufheben.',
                    'journal-entry:' . $first);
            }
            if ($locked['closed']) {
                throw self::refused(VatReturnRefusal::YEAR_CLOSED, 'Das Geschäftsjahr ' . $locked['yearCode'] . ' ist abgeschlossen — wieder öffnen (Admin), dann die MWST-Abrechnung '
                    . $period->label() . ' aufheben.', 'fiscal-year:' . $locked['yearCode']);
            }

            try {
                return $this->writeLift($return, $reason, $actor);                        // 7
            } catch (PostingRefusedException | ReversalRefusedException $e) {
                throw $this->ledgerRefused($e, $period, true);
            }
        });
    }

    /**
     * The fingerprint of the warnings of a plan — message and reference of every warning, in order
     * (the `FiscalYearCloseService::warningsFingerprint()` shape); null when there is none.
     *
     * @param list<VatReturnFinding> $findings
     */
    public static function warningsFingerprint(array $findings): ?string
    {
        $warnings = array_values(array_filter($findings, static fn(VatReturnFinding $f): bool => !$f->blocking));
        if ($warnings === []) {
            return null;
        }

        return hash('sha256', json_encode(array_map(static fn(VatReturnFinding $f): array => [$f->message, $f->reference], $warnings), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** «MWST-Abrechnung 3. Quartal 2026», « (Neuerstellung 2)» from revision 2 — the settlement entry's text. */
    public static function entryText(VatPeriod $period, int $revision): string
    {
        return 'MWST-Abrechnung ' . $period->label() . ($revision > 1 ? ' (Neuerstellung ' . $revision . ')' : '');
    }

    /** `vat-return:2026-07-01:2026-09-30:r1` — always with the revision (financial.md «MWST buchen», the settlement entry). */
    public static function idempotencyKey(VatPeriod $period, int $revision): string
    {
        return VatReturnService::SOURCE_TYPE . ':' . $period->from->format('Y-m-d') . ':' . $period->to->format('Y-m-d') . ':r' . $revision;
    }

    /** `2026-07-01/2026-09-30/r1` — the source reference the voucher of the exact revision is found by. */
    public static function sourceRef(VatPeriod $period, int $revision): string
    {
        return $period->from->format('Y-m-d') . '/' . $period->to->format('Y-m-d') . '/r' . $revision;
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * The plan of $period computed by $service — lock-free (plan()) or after the locks (post()).
     *
     * @param array{yearCode: ?string, closed: bool} $lastDay the fiscal year holding the period's last day and whether that day's period is closed
     * @param array<string, array{postable: bool, active: bool}>|null $flags the accounts' flags read under the lock of step 2 (post());
     *        null = lock-free (plan()) — see {@see accountUsable()}
     * @throws VatReturnRefusedException
     */
    private function planWith(VatReturnService $service, VatPeriod $period, \DateTimeImmutable $today, array $lastDay, ?array $flags = null): VatSettlementPlan
    {
        $gate = $service->settingsRefusal();
        if ($gate !== null) {
            throw new VatReturnRefusedException($gate);
        }
        $ofCalendar = $service->calendar()->periodOn($period->from);
        if ($ofCalendar === null || !$ofCalendar->equals($period)) {
            throw self::refused(VatReturnRefusal::NOT_A_PERIOD, 'Der ' . $period->from->format('d.m.Y') . ' – ' . $period->to->format('d.m.Y') . ' ist keine MWST-Periode des Mandanten — die Liste der MWST-Abrechnungen neu laden.');
        }
        $period  = $ofCalendar;
        $settled = $service->settlement()->returnCovering($period->to);
        if ($settled !== null) {
            throw self::refused(VatReturnRefusal::ALREADY_SETTLED, 'Die MWST-Periode ' . $period->label() . ' ist bereits abgerechnet (gebucht am ' . $settled->getPostedAt()->format('d.m.Y') . ' von ' . $settled->getPostedBy() . ').');
        }
        // A live return of another period inside this one: the calendar was re-cut after returns were posted
        // (rhythm or «Abrechnung ab» changed — the mandator guard is slice 4b). Never a second return over a settled day.
        foreach ($service->settlement()->liveReturns() as $live) {
            if ($live->getPeriodFrom()->format('Y-m-d') <= $period->to->format('Y-m-d') && $live->getPeriodTo()->format('Y-m-d') >= $period->from->format('Y-m-d')) {
                throw self::refused(VatReturnRefusal::PERIOD_OVERLAPS, 'Die MWST-Periode ' . $period->label() . ' überschneidet sich mit der gebuchten MWST-Abrechnung ' . $live->period()->label()
                    . ' — Abrechnungsrhythmus oder «Abrechnung ab» wurden geändert, nachdem Abrechnungen gebucht waren; die Einstellung im Mandanten zurücksetzen (' . VatReturnRefusal::SETTINGS_PLACE . ').', 'mandator:vat');
            }
        }
        $revisions = $service->settlement()->revisionsOf($period);
        $revision  = $revisions === [] ? 1 : $revisions[0]->getRevision() + 1;

        $preview = $service->preview($period);
        $reco    = $service->reconciliation($period);
        $found   = $service->findings($period);

        $own = [];
        if ($period->to >= $today) {
            $own[] = self::blocking(VatReturnRefusal::NOT_ELAPSED, 'Die MWST-Periode ' . $period->label() . ' endet erst am ' . $period->to->format('d.m.Y') . ' — gebucht wird nach ihrem Ende.', 'period');
        } else {
            $next = $service->nextReturn($today);
            if ($next !== null && !$next->equals($period)) {
                $own[] = self::blocking(VatReturnRefusal::NOT_NEXT, 'Zuerst die MWST-Periode ' . $next->label() . ' abrechnen — die Abrechnungen folgen einander ohne Lücke.', 'vat-return:' . $next->from->format('Y-m-d'));
            }
        }
        $blocks = $service->correctionBlocks($period);
        [$lines, $missing, $unbalanced, $unusable] = $this->settlementLines($preview, $reco, $flags, $blocks);
        if ($lastDay['yearCode'] === null) {
            $own[] = self::blocking(VatReturnRefusal::NO_FISCAL_YEAR, 'Kein Geschäftsjahr enthält den ' . $period->to->format('d.m.Y') . ' — zuerst das Geschäftsjahr eröffnen (Finanzen › Geschäftsjahre).', 'fiscal-years');
        } elseif ($lastDay['closed'] && $lines !== []) {
            // A nil return writes no entry — it may settle a period of a closed year (financial.md «MWST buchen», the settlement entry).
            $own[] = self::blocking(VatReturnRefusal::YEAR_CLOSED, 'Das Geschäftsjahr ' . $lastDay['yearCode'] . ' ist abgeschlossen — wieder öffnen (Admin), die MWST abrechnen, wieder abschliessen.', 'fiscal-year:' . $lastDay['yearCode']);
        }
        foreach ($missing as $label) {
            $own[] = self::blocking(VatReturnRefusal::ACCOUNT_MISSING, 'Für die Abrechnungsbuchung ist kein buchbares Konto «' . $label . '» hinterlegt — ' . LedgerService::MANDATOR_HINT . '.', 'mandator:accounts');
        }
        foreach ($unusable as $label) {
            $own[] = self::blocking(VatReturnRefusal::ACCOUNT_MISSING, 'Das MWST-Konto «' . $label . '» ist nicht buchbar (inaktiv, eine Gruppe oder nicht im Kontenplan) — die Abrechnungsbuchung braucht es: das Konto wieder buchbar machen oder ein anderes ' . LedgerService::MANDATOR_HINT . '.', 'mandator:accounts');
        }
        if ($unbalanced !== null) {
            $found[] = new VatReturnFinding(true, VatReturnFinding::SETTLEMENT_UNBALANCED,
                'Die Steuer der codierten Buchungen (' . AmountFormat::of($preview->form->postedTax) . ' Zahllast) steht nicht ganz auf den MWST-Konten — Differenz ' . AmountFormat::of($unbalanced)
                . ': eine Buchung mit einem steuerfreien MWST-Code trägt Steuer; im Reiter «Herkunft» finden und korrigieren.', 'reconciliation', $unbalanced);
        }

        $findings = array_merge($own, $found);
        usort($findings, static fn(VatReturnFinding $a, VatReturnFinding $b): int => $b->blocking <=> $a->blocking);   // stable: preconditions first

        $request = null;
        if ($lines !== [] && $missing === [] && $unusable === [] && $unbalanced === null && !in_array('', array_column($lines, 'account'), true)) {
            $request = PostingRequest::generated(
                $period->to,
                self::entryText($period, $revision),
                VatReturnService::SOURCE_TYPE,
                self::sourceRef($period, $revision),
                self::idempotencyKey($period, $revision),
                array_map(static fn(array $l): PostingLine => new PostingLine($l['account'], $l['debit'], $l['credit'], null, null, null, null, $l['text']), $lines),
            );
        }

        // The hash of the mapping THIS service computed the figures with — never a new read of the rule file.
        return new VatSettlementPlan($period, $revision, $preview, $reco, $findings, $lines, $request,
            self::figuresFingerprint($preview, $lines, $blocks), self::warningsFingerprint($findings), $service->ruleFileHash(), $blocks);
    }

    /**
     * The settlement lines (financial.md «MWST buchen», the settlement entry): each VAT account
     * cleared by the CODED tax of the return (`VatAccountReconciliation::$expected` — owed Dr, input
     * Cr, the other side when negative), the payable amount as declared on the settlement account
     * (Cr owed, Dr a credit), the rest on the rounding account. Zero lines left out; all zero →
     * none (a nil return).
     *
     * The block of correction entries ($blocks — ADR-042 addendum 2 of 2026-11-25, point 2; board
     * S3-2): the reconciliation's expectation holds the block's tax too; the account's share of each
     * corrected period comes off its ordinary line and stands as a line of its own beside it
     * ({@see ROLE_CORRECTION}, «Korrektur {Periode}»), and each corrected period clears its net onto
     * the settlement account as a line of its own after the payable amount — the ESTV invoices a
     * correction apart. The block's lines balance among themselves: the rounding line and the check
     * against the form's rounding difference are as without them.
     *
     * @param array<string, array{postable: bool, active: bool}>|null $flags see {@see planWith()}
     * @param list<VatReturnCorrectionBlock> $blocks the block of the period, one per corrected period
     * @return array{0: list<array{role: string, account: string, debit: Money, credit: Money, text: string}>, 1: list<string>, 2: ?Money, 3: list<string>}
     *         the lines; the labels of the settlement / rounding account a line needs and the
     *         mandator does not name usably; the gap when the balancing line is NOT the form's
     *         rounding difference (else null); «number name» of every VAT account a line posts on
     *         that is not usable (unknown, a group, inactive — an account the mandator leaves EMPTY
     *         is `VatReturnService::findings()`' `vat-account-missing`)
     */
    private function settlementLines(VatReturnPreview $preview, VatReturnReconciliation $reco, ?array $flags, array $blocks = []): array
    {
        $zero     = Money::zero($preview->currency);
        $lines    = [];
        $unusable = [];
        $add      = static function (string $role, string $account, Money $debitSide, string $text) use (&$lines, $zero): bool {
            if ($debitSide->isZero()) {
                return false;
            }
            $lines[] = $debitSide->isPositive()
                ? ['role' => $role, 'account' => $account, 'debit' => $debitSide, 'credit' => $zero, 'text' => $text]
                : ['role' => $role, 'account' => $account, 'debit' => $zero, 'credit' => $debitSide->negate(), 'text' => $text];

            return true;
        };
        // Per corrected period and account: what clears the block's tax there (debit side) — the opposite of
        // what the correction lines moved, Σ sign × tax.
        $clearing = [];
        foreach ($blocks as $i => $block) {
            foreach ($block->items as $item) {
                $account                = (string) ($item['account'] ?? '');
                $clearing[$i][$account] = ($clearing[$i][$account] ?? $zero)->subtract($item['tax']->multiply($item['sign']));
            }
        }
        foreach ($reco->vatAccounts as $account) {
            $owed     = $account->role === VatAccountReconciliation::OWED;
            $role     = $owed ? self::ROLE_OWED : self::ROLE_INPUT;
            $ofBlocks = $zero;
            foreach ($clearing as $byAccount) {
                $ofBlocks = $ofBlocks->add($byAccount[$account->accountNumber] ?? $zero);
            }
            $posted = $add($role, $account->accountNumber, ($owed ? $account->expected : $account->expected->negate())->subtract($ofBlocks), self::LINE_TEXTS[$role]);
            foreach ($blocks as $i => $block) {
                $posted = $add(self::ROLE_CORRECTION, $account->accountNumber, $clearing[$i][$account->accountNumber] ?? $zero, self::correctionText($block->period)) || $posted;
            }
            if ($posted && $account->accountNumber !== '' && !$this->accountUsable($account->accountNumber, $flags)) {
                $unusable[] = trim($account->accountNumber . ' ' . $account->accountName);
            }
        }
        $payable = $preview->form->payable;
        $ledger  = new LedgerService($this->em);
        $missing = [];
        $numbers = [];
        $number  = function (string $key, string $label) use ($ledger, $flags, &$missing, &$numbers): string {
            if (isset($numbers[$key])) {
                return $numbers[$key];
            }
            try {
                $n = $ledger->vatReturnAccount($key);
            } catch (MandatorUnavailableException) {
                $n = null;
            }
            if ($n === null || !$this->accountUsable($n, $flags)) {
                $missing[] = $label;

                return $numbers[$key] = '';
            }

            return $numbers[$key] = $n;
        };
        if (!$payable->isZero()) {
            $add(self::ROLE_SETTLEMENT, $number(LedgerService::VAT_SETTLEMENT_ACCOUNT, 'Abrechnungskonto MWST'), $payable->negate(), self::LINE_TEXTS[self::ROLE_SETTLEMENT]);
        }
        foreach ($blocks as $i => $block) {
            $net = $zero;
            foreach ($clearing[$i] ?? [] as $amount) {
                $net = $net->add($amount);
            }
            if (!$net->isZero()) {
                $add(self::ROLE_CORRECTION, $number(LedgerService::VAT_SETTLEMENT_ACCOUNT, 'Abrechnungskonto MWST'), $net->negate(), self::correctionText($block->period));
            }
        }
        $balance = $zero;
        foreach ($lines as $l) {
            $balance = $balance->add($l['debit'])->subtract($l['credit']);
        }
        // Debit − credit so far = posted − declared: the rounding line takes its other side.
        if (!$balance->isZero()) {
            $add(self::ROLE_ROUNDING, $number(LedgerService::VAT_ROUNDING_ACCOUNT, 'Rundungsdifferenzen MWST'), $balance->negate(), self::LINE_TEXTS[self::ROLE_ROUNDING]);
        }
        $gap = $balance->subtract($preview->form->roundingDifference());

        return [$lines, $missing, $gap->isZero() ? null : $gap, $unusable];
    }

    /**
     * Whether the settlement entry can post on $number: it exists, is postable (no group) and
     * active. With $flags (post(), step 2) the flags read under the share lock decide — the
     * committed state, never an account object an earlier read of the request left in the
     * identity map; an account the lock did not cover (the mandator named another one since the
     * look before the unit of work) and plan() ask `LedgerService::accountExists()`.
     * `LedgerService::post()` checks again under its own rules.
     *
     * @param array<string, array{postable: bool, active: bool}>|null $flags
     */
    private function accountUsable(string $number, ?array $flags): bool
    {
        if ($flags !== null && isset($flags[$number])) {
            return $flags[$number]['postable'] && $flags[$number]['active'];
        }

        return (new LedgerService($this->em))->accountExists($number);
    }

    /**
     * SHA-256 over every field's exact value, the settlement lines and — when there is one — the
     * block of correction entries (corrected period, entries, every row's exact values): what the
     * poster saw. A correction entry added before the click changes it (board S3-2). Without a block
     * the hashed content is what it was before the block existed.
     *
     * @param list<array{role: string, account: string, debit: Money, credit: Money}> $lines
     * @param list<VatReturnCorrectionBlock> $blocks
     */
    private static function figuresFingerprint(VatReturnPreview $preview, array $lines, array $blocks = []): string
    {
        $fields = [];
        foreach ($preview->fields as $f) {
            $fields[$f->field] = $f->value->toDecimal() . ($f->base === null ? '' : '|' . $f->base->toDecimal());
        }
        $content = [
            'fields' => $fields,
            'lines'  => array_map(static fn(array $l): array => [$l['role'], $l['account'], $l['debit']->toDecimal(), $l['credit']->toDecimal()], $lines),
        ];
        if ($blocks !== []) {
            $content['corrections'] = array_map(static fn(VatReturnCorrectionBlock $b): array => [
                $b->period->from->format('Y-m-d'), $b->period->to->format('Y-m-d'), $b->entryRefs(),
                array_map(static fn(array $r): array => [$r['field'], $r['original']->toDecimal(), $r['earlier']->toDecimal(), $r['correction']->toDecimal(),
                    $r['correctionBase']?->toDecimal(), $r['earlierBase']?->toDecimal(), $r['originalBase']?->toDecimal()], $b->rows),
            ], $blocks);
        }

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** «Korrektur 3. Quartal 2026» — the text of a settlement line of the block. */
    public static function correctionText(VatPeriod $corrected): string
    {
        return 'Korrektur ' . $corrected->label();
    }

    /**
     * «Korrekturen früherer Perioden 40.46 (3. Quartal 2026)» — the block in the protocol row
     * «MWST gebucht» (board S3-2): the tax the settlement entry moved onto the settlement account for
     * it, owed positive, and the corrected periods. Null without a block.
     *
     * @param list<VatReturnCorrectionBlock> $blocks
     */
    public static function correctionsSummary(array $blocks, string $currency): ?string
    {
        if ($blocks === []) {
            return null;
        }
        $sum = Money::zero($currency);
        foreach ($blocks as $block) {
            $sum = $sum->add($block->payableTax($currency));
        }

        return 'Korrekturen früherer Perioden ' . AmountFormat::of($sum) . ' (' . implode(', ', array_map(static fn(VatReturnCorrectionBlock $b): string => $b->period->label(), $blocks)) . ')';
    }

    /** @throws VatReturnRefusedException the first precondition, `blocked`, the warnings, `figures-changed` */
    private function assertPostable(VatSettlementPlan $plan, string $figuresFingerprint, ?string $warningsFingerprint): void
    {
        foreach (self::PRECONDITIONS as $reason) {
            foreach ($plan->blocking() as $f) {
                if ($f->code === $reason) {
                    throw new VatReturnRefusedException(new VatReturnRefusal($reason, $f->message, $f->reference));
                }
            }
        }
        if ($plan->isBlocked()) {
            $n = count($plan->blocking());
            throw new VatReturnRefusedException(new VatReturnRefusal(VatReturnRefusal::BLOCKED,
                ($n === 1 ? '1 offener Punkt sperrt' : "{$n} offene Punkte sperren") . ' das Buchen der MWST-Abrechnung ' . $plan->period->label() . ' — siehe «Offene Punkte».'), null, $plan->blocking());
        }
        if ($plan->warnings() !== []) {
            if ($warningsFingerprint === null) {
                throw new VatReturnRefusedException(new VatReturnRefusal(VatReturnRefusal::WARNINGS_UNCONFIRMED,
                    'Die Warnungen der MWST-Abrechnung ' . $plan->period->label() . ' sind nicht bestätigt — lesen und bestätigen, dann buchen.'), null, $plan->warnings());
            }
            if (!hash_equals((string) $plan->warningsFingerprint, $warningsFingerprint)) {
                throw new VatReturnRefusedException(new VatReturnRefusal(VatReturnRefusal::WARNINGS_CHANGED,
                    'Die Warnungen der MWST-Abrechnung ' . $plan->period->label() . ' haben sich seit der Bestätigung geändert — nochmals prüfen.'), null, $plan->warnings());
            }
        }
        if (!hash_equals($plan->figuresFingerprint, $figuresFingerprint)) {
            throw self::refused(VatReturnRefusal::FIGURES_CHANGED,
                'Die Zahlen der MWST-Abrechnung ' . $plan->period->label() . ' haben sich seit der Anzeige geändert (eine Buchung kam dazu) — die Abrechnung neu ansehen; was im ePortal schon eingereicht ist, vergleichen.');
        }
    }

    /** Step 7: the entry, the voucher, the rows. */
    private function write(VatSettlementPlan $plan, string $actor, VatReturnArchive $archive): VatReturn
    {
        $postedAt = new \DateTimeImmutable();
        $entry    = null;
        $ref      = null;
        if ($plan->settlement !== null) {
            $ref = (new LedgerService($this->em, $actor))->post($plan->settlement);
            $this->em->flush();
            $entry = $this->em->getRepository(JournalEntry::class)->findOneBy(['idempotencyKey' => $plan->settlement->idempotencyKey]);
            if (!$entry instanceof JournalEntry) {
                throw new \LogicException('The settlement entry ' . $plan->settlement->idempotencyKey . ' was posted but cannot be read back');
            }
        }
        $period   = $plan->period;
        $preview  = $plan->preview;
        $warnings = $plan->warningMessages();

        $pdf    = VatReturnPdf::voucher($preview, $plan->reconciliation, $plan->lines, $ref === null ? null : self::refLabel($ref), $warnings, $plan->revision,
            $actor, $postedAt, $period->label(), $this->mandator())->output();
        $sha    = hash('sha256', $pdf);
        $path   = $archive->store($pdf, $sha, $period, $plan->revision);

        $return = new VatReturn(
            $period,
            $plan->revision,
            $preview->method,
            $preview->consideration,
            VatReturnService::COUNTRY,
            $preview->edition,
            $plan->mappingHash,
            array_map(static fn(FieldAssignment $a): array => ['code' => $a->taxCode, 'category' => $a->category, 'rate' => $a->rate, 'field' => $a->field, 'kind' => $a->kind->value, 'input' => $a->inputField], $preview->form->assignments),
            $preview->form->payable,
            $preview->form->postedTax,
            $preview->form->roundingDifference(),
            $entry,
            $actor,
            $postedAt,
            $plan->figuresFingerprint,
            $plan->warningsFingerprint,
            $warnings,
            $path,
            $sha,
            VatReturnArchive::fileName($period, $plan->revision),
        );
        $this->em->persist($return);
        foreach ($preview->fields as $f) {
            $this->em->persist(new VatReturnFigure($return, $f->field, $f->kind, $f->value, $f->base, $f->lines));
        }
        // The block, stored with the return — a posted return re-renders it from these rows only.
        $position = 0;
        foreach ($plan->corrections as $block) {
            if ($block->correctedReturn === null) {
                throw new \LogicException('The block of ' . $period->label() . ' corrects ' . $block->period->label() . ', which no live return covers — the plan blocks that');
            }
            foreach ($block->rows as $row) {
                $this->em->persist(new VatReturnCorrection($return, $block->correctedReturn, ++$position, $row['field'], $row['kind'],
                    $row['original'], $row['earlier'], $row['correction'],
                    ['original' => $row['originalBase'], 'earlier' => $row['earlierBase'], 'correction' => $row['correctionBase']], $block->entries));
            }
        }
        $this->em->flush();   // the id the protocol names
        $this->em->persist(new VatReturnLog($return, VatReturnLogAction::Post, $actor, $postedAt, null, array_filter([
            'entry'       => $ref === null ? null : self::refLabel($ref),
            'corrections' => self::correctionsSummary($plan->corrections, $preview->currency),
            'warnings'    => $warnings === [] ? null : $warnings,
            'voucher'     => $path,
        ], static fn(mixed $v): bool => $v !== null)));

        return $return;
    }

    /** Step 7 of {@see lift()}: the reversal, the row, the protocol. */
    private function writeLift(VatReturn $return, string $reason, string $actor): VatReturn
    {
        $liftedAt = new \DateTimeImmutable();
        $entry    = $return->getSettlementEntry();
        $reversal = null;
        $detail   = [];
        if ($entry !== null) {
            $ref  = new EntryRef($entry->getFiscalYear()->getCode(), $entry->getNumber());
            $text = mb_substr('Aufgehoben: ' . $entry->getText() . ' — ' . trim((string) preg_replace('/\s+/u', ' ', $reason)), 0, JournalEntry::TEXT_LENGTH);
            $back = (new LedgerService($this->em, $actor))->reverse($ref, $entry->getDate(), $text);
            $this->em->flush();
            $reversal = $this->em->getRepository(JournalEntry::class)->findOneBy(['idempotencyKey' => 'reversal:' . self::refLabel($ref)]);
            if (!$reversal instanceof JournalEntry) {
                throw new \LogicException('The reversal of ' . self::refLabel($ref) . ' was posted but cannot be read back');
            }
            $detail = ['entry' => self::refLabel($ref), 'reversal' => self::refLabel($back)];
        }
        $return->lift($reversal, $actor, $liftedAt, $reason);
        $this->em->persist($return);
        $this->em->persist(new VatReturnLog($return, VatReturnLogAction::Lift, $actor, $liftedAt, $reason, $detail));

        return $return;
    }

    /**
     * Steps 0–4 of EVERY return writer ({@see post()}, {@see lift()}) — the one place of the lock
     * order: the writer row (X), the journal range of the year the entry goes into (X; none when
     * there is no year / no entry), the accounts the entry posts on (S), every fiscal-year row (S),
     * the period rows of every year the VAT period touches (X, refreshed).
     *
     * @param list<string> $accounts the account numbers, sorted
     * @return array{yearCode: ?string, closed: bool, flags: array<string, array{postable: bool, active: bool}>}
     *         see {@see lockYearsAndPeriods()}; `flags` — postable / active of the locked accounts, as committed
     */
    private function takeWriterLocks(?string $yearCode, array $accounts, VatPeriod $period): array
    {
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        $ranges->lock(self::WRITER_LOCK);                                          // 0
        if ($yearCode !== null) {
            $ranges->lock(FiscalYear::JOURNAL_ENTRY_RANGE_PREFIX . $yearCode);     // 1
        }
        /** @var AccountRepository $accountRows */
        $accountRows = $this->em->getRepository(Account::class);
        $flags       = $accountRows->lockForPosting($accounts);                    // 2

        return $this->lockYearsAndPeriods($period) + ['flags' => $flags];          // 3, 4
    }

    /**
     * Steps 3 and 4: every fiscal-year row (S), then the period rows of every year the VAT period
     * touches (X, refreshed); from the locked rows the year holding the period's last day and
     * whether that day's period is closed.
     *
     * @return array{yearCode: ?string, closed: bool}
     */
    private function lockYearsAndPeriods(VatPeriod $period): array
    {
        $from    = $period->from->format('Y-m-d');
        $to      = $period->to->format('Y-m-d');
        $touched = [];
        $code    = null;
        foreach ($this->years()->shareLockAll() as $row) {
            if ($row['start_date'] <= $to && $row['end_date'] >= $from) {
                $touched[] = $row['id'];
            }
            if ($row['start_date'] <= $to && $row['end_date'] >= $to) {
                $code = $row['code'];
            }
        }
        $closed = false;
        $found  = false;
        /** @var Period $p */
        foreach ($this->years()->lockPeriodsOf($touched) as $p) {
            if ($p->getStartDate()->format('Y-m-d') <= $to && $p->getEndDate()->format('Y-m-d') >= $to) {
                $found  = true;
                $closed = $p->getState() === PeriodState::Closed->value;
            }
        }

        return ['yearCode' => $found ? $code : null, 'closed' => $closed];
    }

    /**
     * Lock-free: the fiscal year holding the period's last day and whether that day's period is
     * closed — what {@see plan()} shows.
     *
     * @return array{yearCode: ?string, closed: bool}
     */
    private function lastDayState(VatPeriod $period): array
    {
        $year = $this->years()->findByDate($period->to);
        $day  = $year?->periodOn($period->to);

        return ['yearCode' => $day === null ? null : $year->getCode(), 'closed' => $day?->getState() === PeriodState::Closed->value];
    }

    /**
     * The account numbers step 2 locks: the VAT accounts of `VatReturnService::vatAccountCategories()`
     * — the very list `VatReturnService::vatAccounts()` maps — and the settlement / rounding
     * account; whatever the mandator names (none when it cannot be read).
     *
     * @return list<string>
     */
    private function settlementAccountNumbers(): array
    {
        $ledger  = new LedgerService($this->em);
        $numbers = [];
        try {
            foreach (VatReturnService::vatAccountCategories() as $category) {
                $numbers[] = $ledger->vatAccountFor($category->value);
            }
            $numbers[] = $ledger->vatReturnAccount(LedgerService::VAT_SETTLEMENT_ACCOUNT);
            $numbers[] = $ledger->vatReturnAccount(LedgerService::VAT_ROUNDING_ACCOUNT);
        } catch (MandatorUnavailableException | VatAccountUnavailableException) {
            // The fresh plan after the locks refuses with the sentence; nothing to lock here.
        }
        $numbers = array_values(array_unique(array_filter($numbers, static fn(?string $n): bool => $n !== null && $n !== '')));
        sort($numbers, SORT_STRING);

        return $numbers;
    }

    private function service(): VatReturnService
    {
        return new VatReturnService($this->em, $this->settings, $this->checks, $this->sources);
    }

    private function mandator(): ?Mandator
    {
        try {
            return (new CurrentMandator($this->em))->find();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    /**
     * Step 6 of post(), before the fresh service: the mandator RE-READ, its identity-map copy
     * refreshed — the settings, the VAT accounts and the settlement / rounding account the plan
     * reads are then the committed ones, whatever an earlier read of this request (the look before
     * the unit of work, a screen) left in memory. A record that cannot be read is left to the
     * settings gate of the plan, which refuses with its sentence.
     */
    private function refreshMandator(): void
    {
        try {
            (new CurrentMandator($this->em))->fresh();
        } catch (MandatorUnavailableException) {
            // VatReturnService::settingsRefusal() names it.
        }
    }

    /**
     * The ledger's refusal of the settlement entry ($lift false) or of its reversal ($lift true) as
     * a `ledger-refused` refusal with ONE German sentence — the ledger's exception rides along as the
     * previous one. Reached only when the checks under the locks passed (an account changed by a
     * path the locks do not cover, a foreign entry under the settlement entry's key).
     */
    private function ledgerRefused(PostingRefusedException|ReversalRefusedException $e, VatPeriod $period, bool $lift): VatReturnRefusedException
    {
        $what = $lift
            ? 'Die MWST-Abrechnung ' . $period->label() . ' lässt sich nicht aufheben'
            : 'Die Abrechnungsbuchung der MWST-Periode ' . $period->label() . ' geht nicht';
        $none = $lift ? 'nichts aufgehoben' : 'nichts gebucht';
        $acct = $e instanceof PostingRefusedException && $e->account !== null ? ' (' . $e->account . ')' : '';
        $reason = $e instanceof PostingRefusedException ? $e->reason : 'reversal-' . $e->reason;

        $message = match (true) {
            $e instanceof IdempotencyConflictException
                => $what . ': unter ihrem Schlüssel ' . $e->idempotencyKey . ' steht schon eine andere Buchung im Journal (' . $e->existing->fiscalYear . '/' . $e->existing->number . ') — ' . $none . '; bitte melden.',
            in_array($reason, [PostingRefusedException::ACCOUNT_UNKNOWN, PostingRefusedException::ACCOUNT_NOT_POSTABLE, PostingRefusedException::ACCOUNT_INACTIVE], true)
                => $what . ': ein Konto der Buchung gibt es nicht mehr, ist eine Gruppe oder inaktiv' . $acct . ' — ' . $none . '; das Konto im Kontenplan wieder buchbar machen'
                    . ($lift ? '.' : ' oder ein anderes ' . LedgerService::MANDATOR_HINT . '.'),
            in_array($reason, [PostingRefusedException::NO_FISCAL_YEAR, PostingRefusedException::NO_PERIOD, PostingRefusedException::PERIOD_CLOSED, PostingRefusedException::VAT_PERIOD_SETTLED, PostingRefusedException::BEFORE_VAT_START], true)
                => $what . ': ' . (new LedgerService($this->em))->periodRefusalText($reason === PostingRefusedException::NO_PERIOD ? PostingRefusedException::NO_FISCAL_YEAR : $reason, $period->to, $e->vatPeriod) . ' — ' . $none . '.',
            default
                => $what . ': die Buchhaltung lehnt die Buchung ab (' . $reason . ') — ' . $none . '; bitte melden.',
        };

        return new VatReturnRefusedException(new VatReturnRefusal(VatReturnRefusal::LEDGER_REFUSED, $message, $acct === '' ? '' : 'account:' . $e->account), $e);
    }

    /** @throws \LogicException a unit of work is already open */
    private function ownUnitOfWork(string $method): TransactionInterface
    {
        $transaction = $this->em->getTransaction(VatReturn::class);
        if ($transaction->isOpen()) {
            throw new \LogicException("VatReturnPostingService::{$method} owns its unit of work — call it outside getTransaction()->run(), so the locks it takes come before its first read and hold until its own commit");
        }

        return $transaction;
    }

    private function actorName(): string
    {
        return $this->actor !== null ? Actor::normalize($this->actor) : Actor::current();
    }

    private function years(): FiscalYearRepository
    {
        return $this->em->getRepository(FiscalYear::class);
    }

    private function returns(): VatReturnRepository
    {
        return $this->em->getRepository(VatReturn::class);
    }

    private function journalLines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }

    private static function refLabel(EntryRef $ref): string
    {
        return $ref->fiscalYear . '/' . $ref->number;
    }

    private static function blocking(string $reason, string $message, string $reference = ''): VatReturnFinding
    {
        return new VatReturnFinding(true, $reason, $message, $reference);
    }

    private static function notFound(): VatReturnRefusedException
    {
        return self::refused(VatReturnRefusal::NOT_FOUND, 'Diese MWST-Abrechnung gibt es nicht.');
    }

    private static function refused(string $reason, string $message, string $fix = ''): VatReturnRefusedException
    {
        return new VatReturnRefusedException(new VatReturnRefusal($reason, $message, $fix));
    }

    private static function day(?\DateTimeImmutable $day): \DateTimeImmutable
    {
        return ($day ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }
}
