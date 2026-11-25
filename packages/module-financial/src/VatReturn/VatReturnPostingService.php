<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Entities\VatReturn;
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
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FieldAssignment;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Interface\TransactionInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «MWST buchen» — the WRITER of the ordinary VAT return (stage S2 slice 5a; ADR-042 addendum
 * 2026-11-24 points 1, 2, 6; ADR-041 addendum points 1, 2, 5, 13, 14; plan
 * `docs/temp/vat-return-s2-plan.md` §2, §3, §8). The service layer only: NO controller action,
 * button or dialog calls it yet (the boards are not decided) — a deploy shows nothing new.
 *
 *   - {@see plan()} — lock-free: what «MWST buchen» would post for one period
 *     ({@see VatSettlementPlan}: the form, the findings with the posting preconditions, the
 *     settlement entry, the two fingerprints). What a confirm dialog shows and hands back.
 *   - {@see post()} — posts it: owns its unit of work, takes the locks below, computes the plan
 *     AGAIN on a fresh {@see VatReturnService} after them and refuses when it is blocked or differs
 *     from what was confirmed; then the settlement entry through `LedgerService::post()`, the
 *     voucher into {@see VatReturnArchive}, the {@see VatReturn} row with one
 *     {@see VatReturnFigure} per form field and one {@see VatReturnLog} row `post`.
 *   - {@see setFiled()} — sets or clears «Eingereicht am» of a posted return (optimistic lock,
 *     log `filed` / `unfiled`). WHO may do it is the screen's (board question 3) — the service
 *     knows no roles.
 *
 * LOCK ORDER of {@see post()} (plan §2.1 with S3 plan O10; every step a LOCKING read, so the
 * unit of work's REPEATABLE READ snapshot starts only at step 6 — plan §0.1):
 *
 *   0. the writer row `NumberRangeRepository::lock('vat-return')` (X) — EVERY return writer takes
 *      it first (S2 post / lift, S3's correction returns); postings never do;
 *   1. the journal range of the fiscal year holding the period's last day (X) — serialises against
 *      every `post()` of that year at ITS first lock;
 *   2. the mandator's VAT accounts and the settlement / rounding account (S) — the position
 *      `post()` takes them in;
 *   3. EVERY fiscal-year row (S) — no year opened, deleted or closed under the return;
 *   4. the period rows of every fiscal year the VAT period touches (X, refreshed) — waits for every
 *      posting / edit / amend / retract in flight there (they hold the period S);
 *   5. the latest live ordinary `vat_return` row (X);
 *   6. the first plain read: a FRESH `VatReturnService` computes the plan;
 *   7. `LedgerService::post()` re-takes 1–4 without waiting.
 *
 * Steps 1 and 2 need the year's code and the account numbers BEFORE the unit of work — read
 * lock-free before it opens; a year that appeared or vanished meanwhile is refused
 * (`fiscal-year-changed`, post again). A lock conflict surfaces as the driver's exception (no
 * retry loop, ADR-039 decision 10).
 *
 * Every refusal is a {@see VatReturnRefusedException} with a stable reason and ONE German sentence
 * (`VatReturnRefusal`); nothing is written then — no entry, no row, no file, the number goes back.
 */
final class VatReturnPostingService
{
    /** The writer-serialisation row every return writer locks first (S3 plan O10) — a `number_range` row that never hands out a number. */
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
     * @throws VatReturnRefusedException the settings gate, `not-a-period`, `already-settled`, or the period cannot be computed (no form, reverse charge, an unmapped or unknown code)
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
     * @param \DateTimeImmutable|null $filedOn             «Eingereicht am» — optional (board question 2); not after today, not before the period's end
     * @param \DateTimeImmutable|null $today               the day «elapsed» and the filed date are judged on; null = today
     * @throws VatReturnRefusedException see `VatReturnRefusal` — the period, the preconditions, `blocked`, the warnings, `figures-changed`, `filed-on-invalid`, `fiscal-year-changed`
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function post(VatPeriod $period, string $figuresFingerprint, ?string $warningsFingerprint, ?\DateTimeImmutable $filedOn = null, ?\DateTimeImmutable $today = null): VatReturn
    {
        $today       = self::day($today);
        $filedOn     = $filedOn === null ? null : self::day($filedOn);
        $transaction = $this->ownUnitOfWork('post()');
        $actor       = $this->actorName();
        self::assertFiledOn($period, $filedOn, $today);

        // Before the unit of work, lock-free: which range and which accounts steps 1 and 2 lock.
        $yearCode = $this->years()->findByDate($period->to)?->getCode();
        $accounts = $this->settlementAccountNumbers();
        $archive  = new VatReturnArchive($this->basePath);

        try {
            return $transaction->run(function () use ($period, $figuresFingerprint, $warningsFingerprint, $filedOn, $today, $actor, $yearCode, $accounts, $archive): VatReturn {
                /** @var NumberRangeRepository $ranges */
                $ranges = $this->em->getRepository(NumberRange::class);
                $ranges->lock(self::WRITER_LOCK);                                                    // 0
                if ($yearCode !== null) {
                    $ranges->lock(FiscalYear::JOURNAL_ENTRY_RANGE_PREFIX . $yearCode);               // 1
                }
                /** @var AccountRepository $accountRows */
                $accountRows = $this->em->getRepository(Account::class);
                $accountRows->lockForPosting($accounts);                                             // 2
                $locked = $this->lockYearsAndPeriods($period);                                       // 3, 4
                if ($locked['yearCode'] !== $yearCode) {
                    throw self::refused(VatReturnRefusal::FISCAL_YEAR_CHANGED, 'Die Geschäftsjahre wurden eben geändert — «MWST buchen» bitte nochmals auslösen.');
                }
                $this->returns()->lockLatestLive();                                                  // 5

                $plan = $this->planWith($this->service(), $period, $today, $locked);                 // 6 — the first plain read
                $this->assertPostable($plan, $figuresFingerprint, $warningsFingerprint);

                return $this->write($plan, $filedOn, $actor, $archive);                              // 7
            });
        } catch (\Throwable $e) {
            $archive->discardWritten();   // no file may lie there that no row names
            throw $e;
        }
    }

    /**
     * Sets or clears «Eingereicht am» of the posted return $returnId — the screen hands back the
     * version it showed. One protocol row: `filed` (set or changed, the date and the one before) or
     * `unfiled` (cleared). An unchanged date writes nothing.
     *
     * @throws VatReturnRefusedException `not-found`, `stale`, `not-posted`, `filed-on-invalid`
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function setFiled(int $returnId, int $version, ?\DateTimeImmutable $filedOn, ?\DateTimeImmutable $today = null): VatReturn
    {
        $today       = self::day($today);
        $filedOn     = $filedOn === null ? null : self::day($filedOn);
        $transaction = $this->ownUnitOfWork('setFiled()');
        $actor       = $this->actorName();

        return $transaction->run(function () use ($returnId, $version, $filedOn, $today, $actor): VatReturn {
            $current = $this->returns()->lockedVersion($returnId);
            if ($current === null) {
                throw self::refused(VatReturnRefusal::NOT_FOUND, 'Diese MWST-Abrechnung gibt es nicht.');
            }
            if ($current !== $version) {
                throw self::refused(VatReturnRefusal::STALE, 'Die MWST-Abrechnung wurde inzwischen geändert — die Seite neu laden und nochmals versuchen.');
            }
            $return = $this->returns()->fresh($returnId);
            if ($return === null) {
                throw self::refused(VatReturnRefusal::NOT_FOUND, 'Diese MWST-Abrechnung gibt es nicht.');
            }
            if (!$return->isLive()) {
                throw self::refused(VatReturnRefusal::NOT_POSTED, 'Die MWST-Abrechnung ' . $return->period()->label() . ' ist aufgehoben — «Eingereicht am» steht nur auf einer gebuchten Abrechnung.');
            }
            self::assertFiledOn($return->period(), $filedOn, $today);
            $was = $return->getFiledOn();
            if ($was?->format('Y-m-d') === $filedOn?->format('Y-m-d')) {
                return $return;
            }
            $return->setFiledOn($filedOn);
            $this->em->persist($return);
            $this->em->persist(new VatReturnLog($return, $filedOn === null ? VatReturnLogAction::Unfiled : VatReturnLogAction::Filed, $actor, new \DateTimeImmutable(), null,
                array_filter(['filed_on' => $filedOn?->format('Y-m-d'), 'was' => $was?->format('Y-m-d')], static fn(?string $v): bool => $v !== null)));

            return $return;
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

    /** `vat-return:2026-07-01:2026-09-30:r1` — always with the revision (plan §3.2). */
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
     * @throws VatReturnRefusedException
     */
    private function planWith(VatReturnService $service, VatPeriod $period, \DateTimeImmutable $today, array $lastDay): VatSettlementPlan
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
        [$lines, $missing, $unbalanced] = $this->settlementLines($preview, $reco);
        if ($lastDay['yearCode'] === null) {
            $own[] = self::blocking(VatReturnRefusal::NO_FISCAL_YEAR, 'Kein Geschäftsjahr enthält den ' . $period->to->format('d.m.Y') . ' — zuerst das Geschäftsjahr eröffnen (Finanzen › Geschäftsjahre).', 'fiscal-years');
        } elseif ($lastDay['closed'] && $lines !== []) {
            // A nil return writes no entry — it may settle a period of a closed year (plan §3.3).
            $own[] = self::blocking(VatReturnRefusal::YEAR_CLOSED, 'Das Geschäftsjahr ' . $lastDay['yearCode'] . ' ist abgeschlossen — wieder öffnen (Admin), die MWST abrechnen, wieder abschliessen.', 'fiscal-year:' . $lastDay['yearCode']);
        }
        foreach ($missing as $label) {
            $own[] = self::blocking(VatReturnRefusal::ACCOUNT_MISSING, 'Für die Abrechnungsbuchung ist kein buchbares Konto «' . $label . '» hinterlegt — ' . LedgerService::MANDATOR_HINT . '.', 'mandator:accounts');
        }
        if ($unbalanced !== null) {
            $found[] = new VatReturnFinding(true, VatReturnFinding::SETTLEMENT_UNBALANCED,
                'Die Steuer der codierten Buchungen (' . AmountFormat::of($preview->form->postedTax) . ' Zahllast) steht nicht ganz auf den MWST-Konten — Differenz ' . AmountFormat::of($unbalanced)
                . ': eine Buchung mit einem steuerfreien MWST-Code trägt Steuer; im Reiter «Herkunft» finden und korrigieren.', 'reconciliation', $unbalanced);
        }

        $findings = array_merge($own, $found);
        usort($findings, static fn(VatReturnFinding $a, VatReturnFinding $b): int => $b->blocking <=> $a->blocking);   // stable: preconditions first

        $request = null;
        if ($lines !== [] && $missing === [] && $unbalanced === null && !in_array('', array_column($lines, 'account'), true)) {
            $request = PostingRequest::generated(
                $period->to,
                self::entryText($period, $revision),
                VatReturnService::SOURCE_TYPE,
                self::sourceRef($period, $revision),
                self::idempotencyKey($period, $revision),
                array_map(static fn(array $l): PostingLine => new PostingLine($l['account'], $l['debit'], $l['credit'], null, null, null, null, self::LINE_TEXTS[$l['role']]), $lines),
            );
        }

        return new VatSettlementPlan($period, $revision, $preview, $reco, $findings, $lines, $request,
            self::figuresFingerprint($preview, $lines), self::warningsFingerprint($findings));
    }

    /**
     * The settlement lines (plan §3.2): each VAT account cleared by the CODED tax of the return
     * (`VatAccountReconciliation::$expected` — owed Dr, input Cr, the other side when negative),
     * the payable amount as declared on the settlement account (Cr owed, Dr a credit), the rest on
     * the rounding account. Zero lines left out; all zero → none (a nil return).
     *
     * @return array{0: list<array{role: string, account: string, debit: Money, credit: Money}>, 1: list<string>, 2: ?Money}
     *         the lines; the labels of the settlement / rounding account a line needs and the
     *         mandator does not name usably; the gap when the balancing line is NOT the form's
     *         rounding difference (else null)
     */
    private function settlementLines(VatReturnPreview $preview, VatReturnReconciliation $reco): array
    {
        $zero  = Money::zero($preview->currency);
        $lines = [];
        $add   = static function (string $role, string $account, Money $debitSide) use (&$lines, $zero): void {
            if ($debitSide->isZero()) {
                return;
            }
            $lines[] = $debitSide->isPositive()
                ? ['role' => $role, 'account' => $account, 'debit' => $debitSide, 'credit' => $zero]
                : ['role' => $role, 'account' => $account, 'debit' => $zero, 'credit' => $debitSide->negate()];
        };
        foreach ($reco->vatAccounts as $account) {
            $owed = $account->role === VatAccountReconciliation::OWED;
            $add($owed ? self::ROLE_OWED : self::ROLE_INPUT, $account->accountNumber, $owed ? $account->expected : $account->expected->negate());
        }
        $payable = $preview->form->payable;
        $ledger  = new LedgerService($this->em);
        $missing = [];
        $number  = function (string $key, string $label) use ($ledger, &$missing): string {
            try {
                $n = $ledger->vatReturnAccount($key);
            } catch (MandatorUnavailableException) {
                $n = null;
            }
            if ($n === null || !$ledger->accountExists($n)) {
                $missing[] = $label;

                return '';
            }

            return $n;
        };
        if (!$payable->isZero()) {
            $add(self::ROLE_SETTLEMENT, $number(LedgerService::VAT_SETTLEMENT_ACCOUNT, 'Abrechnungskonto MWST'), $payable->negate());
        }
        $balance = $zero;
        foreach ($lines as $l) {
            $balance = $balance->add($l['debit'])->subtract($l['credit']);
        }
        // Debit − credit so far = posted − declared: the rounding line takes its other side.
        if (!$balance->isZero()) {
            $add(self::ROLE_ROUNDING, $number(LedgerService::VAT_ROUNDING_ACCOUNT, 'Rundungsdifferenzen MWST'), $balance->negate());
        }
        $gap = $balance->subtract($preview->form->roundingDifference());

        return [$lines, $missing, $gap->isZero() ? null : $gap];
    }

    /**
     * SHA-256 over every field's exact value and the settlement lines — what the poster saw.
     *
     * @param list<array{role: string, account: string, debit: Money, credit: Money}> $lines
     */
    private static function figuresFingerprint(VatReturnPreview $preview, array $lines): string
    {
        $fields = [];
        foreach ($preview->fields as $f) {
            $fields[$f->field] = $f->value->toDecimal() . ($f->base === null ? '' : '|' . $f->base->toDecimal());
        }

        return hash('sha256', json_encode([
            'fields' => $fields,
            'lines'  => array_map(static fn(array $l): array => [$l['role'], $l['account'], $l['debit']->toDecimal(), $l['credit']->toDecimal()], $lines),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
    private function write(VatSettlementPlan $plan, ?\DateTimeImmutable $filedOn, string $actor, VatReturnArchive $archive): VatReturn
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
            $actor, $postedAt, $filedOn, $period->label(), $this->mandator())->output();
        $sha    = hash('sha256', $pdf);
        $path   = $archive->store($pdf, $sha, $period, $plan->revision);

        $return = new VatReturn(
            $period,
            $plan->revision,
            $preview->method,
            $preview->consideration,
            VatReturnService::COUNTRY,
            $preview->edition,
            $this->service()->ruleFileHash(),
            array_map(static fn(FieldAssignment $a): array => ['code' => $a->taxCode, 'category' => $a->category, 'rate' => $a->rate, 'field' => $a->field, 'kind' => $a->kind->value, 'input' => $a->inputField], $preview->form->assignments),
            $preview->form->payable,
            $preview->form->postedTax,
            $preview->form->roundingDifference(),
            $entry,
            $filedOn,
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
        $this->em->flush();   // the id the protocol names
        $this->em->persist(new VatReturnLog($return, VatReturnLogAction::Post, $actor, $postedAt, null, array_filter([
            'entry'    => $ref === null ? null : self::refLabel($ref),
            'filed_on' => $filedOn?->format('Y-m-d'),
            'warnings' => $warnings === [] ? null : $warnings,
            'voucher'  => $path,
        ], static fn(mixed $v): bool => $v !== null)));

        return $return;
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
     * The account numbers step 2 locks: the VAT accounts of the categories with a tax line and the
     * settlement / rounding account — whatever the mandator names (none when it cannot be read).
     *
     * @return list<string>
     */
    private function settlementAccountNumbers(): array
    {
        $ledger  = new LedgerService($this->em);
        $numbers = [];
        try {
            foreach ([TaxCategory::Standard, TaxCategory::InputMaterial, TaxCategory::InputOther] as $category) {
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

    /**
     * «Eingereicht am» is a day after the period has ended and not after today (filed in the
     * portal before it is posted here — ADR-042 addendum point 1). Whether it is optional or
     * mandatory at posting is board question 2 — this service takes it optional.
     *
     * @throws VatReturnRefusedException `filed-on-invalid`
     */
    private static function assertFiledOn(VatPeriod $period, ?\DateTimeImmutable $filedOn, \DateTimeImmutable $today): void
    {
        if ($filedOn === null) {
            return;
        }
        if ($filedOn > $today) {
            throw self::refused(VatReturnRefusal::FILED_ON_INVALID, '«Eingereicht am» ' . $filedOn->format('d.m.Y') . ' liegt in der Zukunft.');
        }
        if ($filedOn <= $period->to) {
            throw self::refused(VatReturnRefusal::FILED_ON_INVALID, '«Eingereicht am» ' . $filedOn->format('d.m.Y') . ' liegt nicht nach dem Ende der MWST-Periode ' . $period->label() . ' (' . $period->to->format('d.m.Y') . ').');
        }
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

    private static function refLabel(EntryRef $ref): string
    {
        return $ref->fiscalYear . '/' . $ref->number;
    }

    private static function blocking(string $reason, string $message, string $reference = ''): VatReturnFinding
    {
        return new VatReturnFinding(true, $reason, $message, $reference);
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
