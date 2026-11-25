<?php

/**
 * VAT return, stage S2 — the POSTED return (CLI): `vat_return`, `vat_return_figure`,
 * `vat_return_log`, the reader of «settled» `VatReturn\VatSettlement`, and what
 * `VatReturnService` reports from them (ADR-042 addendum 2026-11-24 points 1, 2, 4, 5; ADR-041
 * addendum points 1, 2, 5, 14; docs/temp/vat-return-s2-plan.md §1, §2, §3). Slice 1 «model +
 * read», slice 2 «the freeze», slice 4a «Abrechnung ab» and slice 5a «MWST buchen», the service
 * layer (`VatReturnPostingService` — no screen calls it yet).
 *
 *   O. (offline) `ExactAmount::fromDecimal()` (integers only), `VatPeriod::stored()` (the short
 *      flag of a snapshotted period), the entities' invariants and transitions in memory (lift,
 *      «Eingereicht am»), source guards (one reader of «settled», no writer yet);
 *   M. (database) the schema: the three tables, their columns, the unique indexes with `kind`
 *      leading (owner O11) and how they behave — at most ONE live return per kind and period, a
 *      lifted row (live NULL) beside it, a correction / annual row beside an ordinary one —, the
 *      foreign keys, `diff` clean, down() / up(); then the books (KMU chart, 2026, a liable mandator,
 *      a coded invoice and the settlement-like entries the rows point at);
 *   A. rows inserted by the harness, and what the service then reports: a posted Q1 (settled,
 *      «gebucht», the declared payable, the figures read back exact, the PDF's state line), lifted
 *      (open again, the note, the protocol), a revision 2, a nil return with a filed date (the
 *      latest), only ORDINARY returns settle, the locking read sees a commit the snapshot does not,
 *      the unique index under the entity, the foreign key to the settlement entry;
 *   F. the FREEZE (ADR-042 addendum point 3): post / amend / retract / update / delete / reverse ×
 *      tax line / none × a settled / an open VAT period — refused `vat-period-settled` exactly where
 *      a tax line meets a settled date (old or new), nothing written, no number drawn; a lifted
 *      return and a correction row freeze nothing; `LedgerService::periodRefusal()` and its German
 *      text; VR-R9 — the settled check under lock is a LOCKING read (a return committed after the
 *      unit of work's first read is seen by post / amend / retract); VR-R2s – R4s — a stand-in for
 *      «MWST buchen» in flight (a second process holding the locks of plan §2.1 with an uncommitted
 *      return): a tax posting and a manual edit WAIT and are refused, a posting without a tax line
 *      waits and is accepted; VR-R10 — source guards (the locked check follows the period lock);
 *   S. «Abrechnung ab» (slice 4, plan §5.1; ADR-041 addendum point 3): a tax line dated before the
 *      liable mandator's day is refused `before-vat-start` — post, a reversal dated before, an amend /
 *      manual update that gives an entry a tax line there or moves one earlier —; an existing tax
 *      entry before the day may be corrected on its date or later, retracted, deleted, reversed on
 *      or after the day; no tax line, no rule; not liable or no day set, no rule (ADR-042 addendum
 *      point 6); `periodRefusal()` and the German sentence.
 *   P. «MWST buchen» — the REAL writer (slice 5a, `VatReturn\VatReturnPostingService`; plan §2.1,
 *      §3): fiscal years 2027 (1.1.–31.8.2027, short) and 2028 (1.9.2027–31.8.2028), «Abrechnung
 *      ab» 1.1.2027; the year-close check names the unsettled periods; the refusals with their
 *      sentences (not-elapsed, not-next, not-a-period, filed-on-invalid, already-settled, the
 *      warnings, figures-changed, account-missing, year-closed, blocked — an uncoded line on 2200,
 *      one account named for owed AND input tax, open work of the registry), nothing written by
 *      any; a NIL return (no entry, a voucher); Q2 posted (entry, row, figures, mapping snapshot
 *      and hash, protocol, voucher), the freeze live; «Eingereicht am» set / cleared;
 *   G. golden case G14 — a VAT period SPANNING two fiscal years and the year close: the close of
 *      2027 waits only for the periods ending in it, Q3 2027 (July / August in the closed 2027,
 *      September in 2028) settles into 2028 with the worked figures; the hint without «Abrechnung ab»;
 *   R. two processes (plan §2.3): VR-R1 a tax posting in flight → the return waits, then
 *      `figures-changed`; VR-R2 / R3 / R4 a return in flight → a tax posting, amend, retract, manual
 *      update and delete wait and are refused, a posting without a tax line waits and passes;
 *      VR-R6 two «MWST buchen» of one period; VR-R8 a type change of a VAT account; VR-R5 a return
 *      and a year close in both orders — no deadlock.
 *
 * Checkpoints: `php tests/financial-vat-return-posting.php --from=A` / `F` / `S` / `P` / `G` / `R`
 * (a full run saves them; `--from=A` runs everything from A on).
 * Every name and amount here is synthetic. Sections A–S insert their return rows BY THE HARNESS (the
 * model and the freeze before a writer existed); P–R post through the service.
 *
 * Run: php tests/run.php financial-vat-return-posting
 * O needs the monorepo's vendor/; M and A a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-vat-return-posting: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/support/HarnessCheckpoint.php';

/** A fresh DI on the installation at $base — the main run's `$wire` and the workers of section R share it. */
function harnessDi(string $base): \Z77\Persistence\Resolver\UnifiedEntityManager
{
    \Z77\Core\DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return \Z77\Core\DI::getUnifiedEntityManager();
}

/**
 * Waits (at most $seconds) until ANOTHER session of this user has been executing one statement for
 * 300 ms or more — a lock wait (PROCESSLIST shows the user's own sessions without the PROCESS
 * privilege; MariaDB's TIME_MS; a native prepared statement runs as «Execute», not «Query»). True when one did; the holder then commits.
 */
function awaitWaiter(\Doctrine\DBAL\Connection $watcher, float $seconds = 20.0): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ((int) $watcher->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND COMMAND <> 'Sleep' AND INFO IS NOT NULL AND TIME_MS >= 300") > 0) {
            usleep(200000);   // let the waiter settle in its wait, then release
            return true;
        }
        usleep(100000);
    }

    return false;
}

/** An open-work check that HOLDS a worker's unit of work once (section R): asked inside an open unit of work, it calls the hold. */
final class HarnessPauseCheck implements \Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface
{
    public static ?\Closure $hold = null;

    public function check(string $scope, array $parameters): iterable
    {
        $hold = self::$hold;
        if ($hold !== null && \Z77\Core\DI::getUnifiedEntityManager()->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->isOpen()) {
            self::$hold = null;
            $hold();
        }

        return [];
    }
}

/** A stand-in for debtor's `JournalForVatReturnCheck` (section P): an invoice of the period waits in the Faktura-Journal. */
final class HarnessOpenInvoiceCheck implements \Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface
{
    public function check(string $scope, array $parameters): iterable
    {
        yield \Z77\Persistence\Doctrine\OpenWork\Finding::blocking('1 Rechnung dieser Periode im Faktura-Journal noch nicht verbucht — zuerst «Journal verbuchen».', 'invoice-journal');
    }
}

// ── worker mode (sections F and R, the two-process probes) ──────────────────
// The REAL services (section R): `--worker {base} postreturn {from} {to} {today}` posts the return of
// that quarter and HOLDS inside its unit of work (an open-work check, after every lock); `holdpost
// {base} {date} {key}` posts a tax entry and holds before its commit; `holdclose {base} {yearCode}`
// closes the year and holds in its close check. Each writes `{base}/{mode}.ready` when it holds and
// releases once another session waits ≥ 300 ms (or after 20 s); prints `ok waited|alone …`,
// `refused {reason} waited|alone`, or the exception.
if (($argv[1] ?? '') === '--worker' && in_array($argv[3] ?? '', ['postreturn', 'holdpost', 'holdclose'], true)) {
    [$workerBase, $workerMode] = [$argv[2], $argv[3]];
    define('ABS_BASE_PATH', $workerBase);
    define('DEBUG', false);
    $cfg     = require $workerBase . '/config/client/database.inc.php';
    $watcher = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $cfg['host'], 'dbname' => $cfg['name'], 'user' => $cfg['user'], 'password' => $cfg['password']]);
    $em      = harnessDi($workerBase);
    $waited  = false;
    $hold    = static function () use ($workerBase, $workerMode, $watcher, &$waited): void {
        file_put_contents("{$workerBase}/{$workerMode}.ready", '1');
        $waited = awaitWaiter($watcher);
    };
    $how = static function () use (&$waited): string { return $waited ? 'waited' : 'alone'; };   // by reference: the hold sets it later
    try {
        if ($workerMode === 'postreturn') {
            $period = new \Z77\Module\Financial\VatReturn\VatPeriod(new \DateTimeImmutable($argv[4]), new \DateTimeImmutable($argv[5]), \Z77\Module\Mandator\Entities\VatRhythm::Quarter);
            $today  = new \DateTimeImmutable($argv[6]);
            $svc    = new \Z77\Module\Financial\VatReturn\VatReturnPostingService($em, 'worker', new \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks(['vat-return' => [HarnessPauseCheck::class]]));
            $plan   = $svc->plan($period, $today);
            HarnessPauseCheck::$hold = $hold;
            $return = $svc->post($period, $plan->figuresFingerprint, $plan->warningsFingerprint, null, $today);
            echo 'ok ' . $how() . ' #' . $return->getId();
        } elseif ($workerMode === 'holdpost') {
            [$date, $key] = [$argv[4], $argv[5]];
            $em->getTransaction(\Z77\Module\Financial\Entities\JournalEntry::class)->run(function () use ($em, $date, $key, $hold): void {
                (new \Z77\Module\Financial\Services\LedgerService($em, 'worker'))->post(\Z77\Module\Financial\Ledger\PostingRequest::generated(new \DateTimeImmutable($date), 'R gehaltene Rechnung', 'harness', $key, $key, [
                    \Z77\Module\Financial\Ledger\PostingLine::debit('1100', \Z77\Shared\Money\Money::fromDecimal('540.50', 'CHF')),
                    \Z77\Module\Financial\Ledger\PostingLine::credit('3200', \Z77\Shared\Money\Money::fromDecimal('500.00', 'CHF'), 'Ware', 'UN', 810, \Z77\Shared\Money\Money::fromDecimal('500.00', 'CHF'), \Z77\Shared\Money\Money::fromDecimal('40.50', 'CHF')),
                    \Z77\Module\Financial\Ledger\PostingLine::credit('2200', \Z77\Shared\Money\Money::fromDecimal('40.50', 'CHF'), 'MWST UN'),
                ]));
                $em->flush();
                $hold();
            });
            echo 'ok ' . $how();
        } else {
            HarnessPauseCheck::$hold = $hold;
            $yearId = (int) $watcher->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$argv[4]]);
            (new \Z77\Module\Financial\Services\FiscalYearCloseService($em, 'worker', new \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks(['period-close' => [\Z77\Module\Financial\Close\UnsettledVatPeriodsCheck::class, HarnessPauseCheck::class]])))->close($yearId);
            echo 'ok ' . $how();
        }
    } catch (\Z77\Module\Financial\VatReturn\VatReturnRefusedException $e) {
        echo 'refused ' . $e->refusal->reason . ' ' . $how();
    } catch (\Z77\Module\Financial\Services\FiscalYearCloseRefusedException $e) {
        echo 'refused ' . $e->reason . ' ' . $how();
    } catch (\Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

// ── worker mode (section F, the two-process probes) ─────────────────────────
// `--worker {base} holdreturn {yearCode} {from} {to}` — a STAND-IN for «MWST buchen» in flight (no
// production code posts a return before slice 5): one transaction takes the locks of plan §2.1 in
// their order — the year's journal number range (X), every fiscal-year row (S), the period rows of
// the year (X), the latest live return (X) —, inserts a live ordinary return {from}..{to} and holds
// it UNCOMMITTED. It writes `{base}/holdreturn.ready` and commits only once another session is seen
// waiting at the number range (a posting) or at a period row (a manual edit), or after 20 s.
// Prints `ok waited` / `ok alone`, or the exception.
if (($argv[1] ?? '') === '--worker') {
    [$workerBase, $workerMode] = [$argv[2], $argv[3]];
    if ($workerMode !== 'holdreturn') {
        echo "unknown worker mode {$workerMode}";
        exit(2);
    }
    [$workerYear, $workerFrom, $workerTo] = [$argv[4], $argv[5], $argv[6]];
    $cfg     = require $workerBase . '/config/client/database.inc.php';
    $connect = static fn() => \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $cfg['host'], 'dbname' => $cfg['name'], 'user' => $cfg['user'], 'password' => $cfg['password']]);
    $holder  = $connect();
    $watcher = $connect();
    try {
        $holder->beginTransaction();
        $holder->fetchOne('SELECT last_number FROM number_range WHERE name = ? FOR UPDATE', ['journal-entry.' . $workerYear]);
        $holder->fetchFirstColumn('SELECT id FROM fiscal_year LOCK IN SHARE MODE');
        $yearId = $holder->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$workerYear]);
        $holder->fetchFirstColumn('SELECT id FROM fiscal_period WHERE fiscal_year_id = ? ORDER BY start_date FOR UPDATE', [$yearId]);
        $holder->fetchOne("SELECT id FROM vat_return WHERE kind = 'ordinary' AND live = 1 ORDER BY period_to DESC LIMIT 1 FOR UPDATE");
        $holder->insert('vat_return', [
            'kind' => 'ordinary', 'period_from' => $workerFrom, 'period_to' => $workerTo, 'revision' => 1, 'state' => 'posted', 'live' => 1,
            'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
            'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
            'rounding_difference' => '0.00', 'posted_by' => 'worker', 'posted_at' => '2027-01-10 10:00:00', 'figures_fingerprint' => str_repeat('b', 64),
            'voucher_path' => 'data/archive/financial/vat-return/2026/worker.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'worker.pdf',
        ]);
        file_put_contents($workerBase . '/holdreturn.ready', '1');
        $waited = false;
        for ($t = 0; $t < 200; $t++) {
            // A statement still executing = it waits (PROCESSLIST shows the same user's sessions without the PROCESS privilege).
            if ((int) $watcher->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND (INFO LIKE '%INTO number\\_range%' OR INFO LIKE '%FROM fiscal\\_period WHERE id =%LOCK IN SHARE MODE%')") > 0) {
                usleep(200000);   // let the waiter settle in its wait, then commit
                $waited = true;
                break;
            }
            usleep(100000);
        }
        $holder->commit();
        echo $waited ? 'ok waited' : 'ok alone';
    } catch (\Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

use Z77\Module\Financial\Close\UnsettledVatPeriodsCheck;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnFigure;
use Z77\Module\Financial\Entities\VatReturnKind;
use Z77\Module\Financial\Entities\VatReturnLog;
use Z77\Module\Financial\Entities\VatReturnLogAction;
use Z77\Module\Financial\Entities\VatReturnState;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Pdf\VatReturnArchive;
use Z77\Module\Financial\Pdf\VatReturnPdf;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\Services\EntryNotEditableException;
use Z77\Module\Financial\Services\AccountService;
use Z77\Module\Financial\Services\FiscalYearCloseRefusedException;
use Z77\Module\Financial\Services\FiscalYearCloseService;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\ManualEntryService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnPostingService;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnRefusedException;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatSettlementPlan;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FieldAssignment;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Shared\Money\Money;

$checkpoint  = new HarnessCheckpoint('financial-vat-return-posting', __FILE__, ['A', 'F', 'S', 'P', 'G', 'R']);
$fromSection = $checkpoint->requested($argv);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/** The exception thrown by $fn, or null. */
function thrown(callable $fn): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e; }
    return null;
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

/** The names of the parts of a check that are false — its detail. */
function falseOf(array $parts): string
{
    return implode(', ', array_keys(array_filter($parts, static fn(bool $ok): bool => !$ok)));
}

/** A posted ordinary return in memory — the defaults of a harness row, each overridable. */
function memoryReturn(array $o = []): VatReturn
{
    $o += [
        'period' => new VatPeriod(day('2026-01-01'), day('2026-03-31'), VatRhythm::Quarter), 'revision' => 1,
        'hash' => hash('sha256', 'rules'), 'snapshot' => [['code' => 'UN', 'category' => 'standard', 'rate' => 810, 'field' => '303', 'kind' => 'rate', 'input' => null]],
        'payable' => chf('81.00'), 'postedTax' => chf('81.00'), 'rounding' => chf('0.00'), 'entry' => null, 'filedOn' => null,
        'figures' => hash('sha256', 'figures'), 'warningsFp' => null, 'warnings' => [], 'path' => 'data/archive/financial/vat-return/2026/x.pdf',
        'pdfHash' => hash('sha256', 'pdf'), 'name' => 'mwst-abrechnung-1-quartal-2026.pdf', 'country' => 'CH',
    ];

    return new VatReturn($o['period'], $o['revision'], VatMethod::Effective, VatConsideration::Agreed, $o['country'], 'CH 2024', $o['hash'], $o['snapshot'],
        $o['payable'], $o['postedTax'], $o['rounding'], $o['entry'], $o['filedOn'], 'harness', day('2026-04-10 09:30:00'), $o['figures'],
        $o['warningsFp'], $o['warnings'], $o['path'], $o['pdfHash'], $o['name']);
}

// ─────────────────────────────────────────────────────────────────────────
echo "O. Exact decimals, stored periods, the entities in memory, source guards (offline)\n";

$roundTrip = static fn(string $d): bool => ExactAmount::fromDecimal($d, 'CHF')->toDecimal() === $d;
check('O1 ExactAmount::fromDecimal() reads back every toDecimal() exactly — sub-Rappen, negative, zero, large — and pads fewer decimals; it refuses a seventh decimal, an exponent, text',
    $roundTrip('958.822110') && $roundTrip('-0.000001') && $roundTrip('0.000000') && $roundTrip('-12345678.123456') && $roundTrip('80.999190')
    && ExactAmount::fromDecimal('12.5', 'CHF')->toDecimal() === '12.500000' && ExactAmount::fromDecimal('7', 'CHF')->equals(ExactAmount::ofMoney(chf('7.00')))
    && ExactAmount::taxOf(chf('999.99'), 810)->equals(ExactAmount::fromDecimal(ExactAmount::taxOf(chf('999.99'), 810)->toDecimal(), 'CHF'))
    && thrown(fn() => ExactAmount::fromDecimal('1.0000001', 'CHF')) instanceof \InvalidArgumentException
    && thrown(fn() => ExactAmount::fromDecimal('1e5', 'CHF')) instanceof \InvalidArgumentException
    && thrown(fn() => ExactAmount::fromDecimal('abc', 'CHF')) instanceof \InvalidArgumentException);
check('O2 VatPeriod::stored() derives `short` from the grid of the rhythm: a full quarter, a month, a year are not short; 15.5.–30.6. (quarter) and 1.7.–31.12. (year) are',
    !VatPeriod::stored(day('2026-01-01'), day('2026-03-31'), VatRhythm::Quarter)->short && !VatPeriod::stored(day('2026-02-01'), day('2026-02-28'), VatRhythm::Month)->short
    && !VatPeriod::stored(day('2026-01-01'), day('2026-12-31'), VatRhythm::Year)->short && VatPeriod::stored(day('2026-05-15'), day('2026-06-30'), VatRhythm::Quarter)->short
    && VatPeriod::stored(day('2026-07-01'), day('2026-12-31'), VatRhythm::Year)->short && VatPeriod::stored(day('2026-04-01 15:30'), day('2026-06-30'), VatRhythm::Quarter)->from->format('H:i') === '00:00');

$r = memoryReturn(['warnings' => ["Ertragskonto «3400»:\n zwei Buchungen  ohne Code", 'Rundung'], 'warningsFp' => hash('sha256', 'w'), 'name' => "mwst\x01abrechnung.pdf", 'filedOn' => day('2026-04-12 13:00')]);
check('O3 a new return is ORDINARY, posted and live, revision as given; the period, rhythm, method and consideration read back as enums; the mapping snapshot and the confirmed warnings (one line each) round-trip; the voucher name loses control characters; the filed date is a day',
    $r->getKind() === VatReturnKind::Ordinary && $r->getState() === VatReturnState::Posted && $r->isLive() && $r->getRevision() === 1 && $r->isNil()
    && $r->period()->equals(new VatPeriod(day('2026-01-01'), day('2026-03-31'), VatRhythm::Quarter)) && !$r->period()->short
    && $r->getRhythm() === VatRhythm::Quarter && $r->getMethod() === VatMethod::Effective && $r->getConsideration() === VatConsideration::Agreed
    && $r->getMappingSnapshot()[0]['field'] === '303' && $r->getMappingSnapshot()[0]['input'] === null
    && $r->getConfirmedWarnings() === ['Ertragskonto «3400»: zwei Buchungen ohne Code', 'Rundung'] && $r->getVoucherName() === 'mwst abrechnung.pdf'
    && $r->getFiledOn()?->format('Y-m-d H:i') === '2026-04-12 00:00' && $r->getCurrency() === 'CHF' && $r->getPayable()->equals(chf('81.00')));
check('O4 the constructor refuses a revision 0, a hash that is not lower-case SHA-256 hex, amounts in two currencies, an empty voucher path, a country that is not an ISO code',
    thrown(fn() => memoryReturn(['revision' => 0])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['hash' => strtoupper(hash('sha256', 'x'))])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['figures' => 'abc'])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['rounding' => Money::fromDecimal('0.00', 'EUR')])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['path' => ''])) instanceof \LogicException
    && thrown(fn() => memoryReturn(['country' => 'che'])) instanceof \LogicException);

$year  = new FiscalYear('2026', day('2026-01-01'), day('2026-12-31'));
$entry = new JournalEntry($year, 1, day('2026-03-31'), 'MWST-Abrechnung', EntryKind::Generated, 'vat-return', '2026-01-01/2026-03-31/r1', 'vat-return:2026-01-01:2026-03-31:r1', 'harness', day('2026-04-10'));
$nil   = memoryReturn(['filedOn' => day('2026-04-12')]);
$filedRefused = thrown(fn() => $nil->lift(null, 'admin', day('2026-04-20'), 'Irrtum')) instanceof \LogicException;
$nil->setFiledOn(null);
$nil->lift(null, 'admin', day('2026-04-20 10:00'), '  Irrtümlich gebucht  ');
$withEntry = memoryReturn(['entry' => $entry]);
check('O5 lift(): refused while a filed date is set; after clearing it a nil return is lifted without a reversal — state lifted, no longer live, who / when / the trimmed reason; a second lift, a filed date on a lifted return, a return WITH a settlement entry lifted without a reversal, an empty or too long reason are refused',
    $filedRefused && $nil->getState() === VatReturnState::Lifted && !$nil->isLive() && $nil->getLiftedBy() === 'admin' && $nil->getLiftReason() === 'Irrtümlich gebucht'
    && $nil->getLiftedAt()?->format('Y-m-d H:i') === '2026-04-20 10:00' && $nil->getReversalEntry() === null && $nil->isNil()
    && thrown(fn() => $nil->lift(null, 'admin', day('2026-04-21'), 'nochmals')) instanceof \LogicException
    && thrown(fn() => $nil->setFiledOn(day('2026-04-22'))) instanceof \LogicException
    && thrown(fn() => $withEntry->lift(null, 'admin', day('2026-04-21'), 'x')) instanceof \LogicException
    && thrown(fn() => memoryReturn()->lift($entry, 'admin', day('2026-04-21'), 'x')) instanceof \LogicException
    && thrown(fn() => $withEntry->lift($entry, 'admin', day('2026-04-21'), '   ')) instanceof \LogicException
    && thrown(fn() => $withEntry->lift($entry, 'admin', day('2026-04-21'), str_repeat('x', 501))) instanceof \LogicException
    && $withEntry->getState() === VatReturnState::Posted);

$figure = new VatReturnFigure($r, '303', FieldKind::Rate, ExactAmount::taxOf(chf('999.99'), 810), chf('999.99'), 1);
check('O6 a declared figure keeps the exact value (read back through integers) and its Rappen; a base only for a rate / acquisition field, and there always; a field number of at most eight characters; a figure in another currency is refused',
    $figure->getValueExact()->toDecimal() === '80.999190' && $figure->getValueRounded()->equals(chf('81.00')) && $figure->getBase()?->equals(chf('999.99'))
    && $figure->getKind() === FieldKind::Rate && $figure->getLineCount() === 1
    && thrown(fn() => new VatReturnFigure($r, '200', FieldKind::Total, ExactAmount::zero('CHF'), chf('1.00'), 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnFigure($r, '303', FieldKind::Rate, ExactAmount::zero('CHF'), null, 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnFigure($r, '123456789', FieldKind::Total, ExactAmount::zero('CHF'), null, 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnFigure($r, '200', FieldKind::Total, ExactAmount::zero('EUR'), null, 0)) instanceof \LogicException
    && thrown(fn() => new VatReturnLog($r, VatReturnLogAction::Post, 'harness', day('2026-04-10'))) instanceof \LogicException);

// Source guards — the literal paths are this harness's coverage for the runner's --changed.
$sources = [];
foreach (glob($root . '/packages/*/src/{,*/,*/*/}*.php', GLOB_BRACE) as $file) {
    $sources[str_replace('\\', '/', substr($file, strlen($root) + 1))] = (string) file_get_contents($file);
}
/** The source without its comments — a docblock may name the table, code may not. */
$codeOf = static fn(string $src): string => implode('', array_map(static fn($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($src)));
$tableUsers = array_keys(array_filter($sources, static fn(string $src): bool => str_contains($src, 'vat_return') && preg_match('/\bvat_return\b/', $codeOf($src)) === 1));
sort($tableUsers);
$writers = array_keys(array_filter($sources, static fn(string $src): bool => preg_match('/new\s+(\\\\?[\w\\\\]*\\\\)?VatReturn(Figure|Log)?\s*\(/', $src) === 1));
$serviceSrc = $sources['packages/module-financial/src/VatReturn/VatReturnService.php'] ?? '';
check('O7 «settled» has ONE reader: only the entity mapping and VatReturnRepository name the table `vat_return` in the code of packages/*/src; VatReturnService asks VatSettlement (no constant false left); ONE writer constructs a VatReturn, a figure and a log row — VatReturnPostingService (slice 5a)',
    $tableUsers === ['packages/module-financial/src/Entities/VatReturn.php', 'packages/module-financial/src/Repositories/VatReturnRepository.php']
    && $writers === ['packages/module-financial/src/VatReturn/VatReturnPostingService.php'] && str_contains($serviceSrc, '$this->settlement()->isSettled($period->to)') && !preg_match('/function isSettled\(VatPeriod \$period\): bool\s*\{\s*return false;/', $serviceSrc)
    && !preg_match('/\(float\)|floatval\(/', implode("\n", array_filter($sources, static fn(string $k): bool => str_contains($k, 'VatSettlement') || str_contains($k, 'Entities/VatReturn'), ARRAY_FILTER_USE_KEY))),
    json_encode([$tableUsers, $writers]));

// ── M. database ──────────────────────────────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $credLine) {
        if (str_starts_with($credLine, $credentials['user'] . '=')) {
            $credentials['password'] = substr($credLine, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '' || !class_exists(\Doctrine\DBAL\DriverManager::class)) {
    echo "M. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed (M skipped)\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database — the schema of the posted returns (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-vat-return-posting-' . getmypid());
define('DEBUG', false);
$base = ABS_BASE_PATH;

$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $root . '/packages/module-financial',
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['vat' => [], 'mandator' => [], 'financial' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
$write('data/framework/vat/tax_codes.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'));
$write('data/framework/vat/tax_rates.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'));

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map. */
$wire = static function () use ($base): \Z77\Persistence\Resolver\UnifiedEntityManager {
    gc_collect_cycles();
    static $previous = null;
    if ($previous !== null) {
        foreach ((new \ReflectionProperty($previous, 'managerCache'))->getValue($previous) as $manager) {
            if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
                (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection()->close();
            }
        }
    }
    return $previous = harnessDi($base);
};
$em = $wire();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

// ── shared helpers — up front, so a section started from the checkpoint has them too ──

$settings = static fn(): VatSettings => new VatSettings(true, VatMethod::Effective, VatConsideration::Agreed, VatRhythm::Quarter, day('2026-01-01'), null);
/** A service on the given request (fresh by default): the harness's settings, no open work. */
$service = static fn(?\Z77\Persistence\Resolver\UnifiedEntityManager $on = null): VatReturnService => new VatReturnService($on ?? $wire(), $settings(), new OpenWorkChecks([]));
$quarter = static fn(int $q): VatPeriod => new VatPeriod(day(sprintf('2026-%02d-01', $q * 3 - 2)), day(sprintf('2026-%02d-01', $q * 3 - 2))->modify('+3 months -1 day'), VatRhythm::Quarter);
$post    = static function (PostingRequest $request) use ($wire): EntryRef {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($em, 'harness'))->post($request));
};
$count   = static fn(string $table, string $where = '1 = 1'): int => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$where}");
/** A raw row of `vat_return` — every NOT NULL column filled, each overridable. */
$rawReturn = static function (array $o = []) use ($db): int {
    $db->insert('vat_return', $o + [
        'kind' => 'ordinary', 'period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'revision' => 1, 'state' => 'posted', 'live' => 1,
        'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
        'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
        'rounding_difference' => '0.00', 'posted_by' => 'harness', 'posted_at' => '2026-04-10 10:00:00', 'figures_fingerprint' => str_repeat('b', 64),
        'voucher_path' => 'data/archive/financial/vat-return/2026/harness.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness.pdf',
    ]);

    return (int) $db->lastInsertId();
};
/** The message of a unique / foreign-key violation $fn raises, '' when none. */
$violation = static function (callable $fn): string {
    try { $fn(); } catch (\Doctrine\DBAL\Exception\ConstraintViolationException $e) { return $e->getMessage(); }
    return '';
};
/** Runs $fn in a transaction of the harness connection and rolls it back — the probe leaves nothing. */
$probe = static function (callable $fn) use ($db): mixed {
    $db->beginTransaction();
    try { return $fn(); } finally { $db->rollBack(); }
};
$entryByKey = static fn(\Z77\Persistence\Resolver\UnifiedEntityManager $on, string $key): ?JournalEntry => $on->getRepository(JournalEntry::class)->findOneBy(['idempotencyKey' => $key]);
/** A posted ordinary return built the way «MWST buchen» will build it: from the preview of the period, every field a figure. */
$persistReturn = static function (VatPeriod $period, int $revision, ?string $entryKey, ?string $filedOn = null, array $warnings = []) use ($wire, $service, $entryByKey): int {
    $em = $wire();

    return $em->getTransaction(VatReturn::class)->run(function () use ($em, $period, $revision, $entryKey, $filedOn, $warnings, $service, $entryByKey): int {
        $preview = $service($em)->preview($period);
        $form    = $preview->form;
        $mapping = array_map(static fn(FieldAssignment $a): array => ['code' => $a->taxCode, 'category' => $a->category, 'rate' => $a->rate, 'field' => $a->field, 'kind' => $a->kind->value, 'input' => $a->inputField], $form->assignments);
        $return  = new VatReturn($period, $revision, $preview->method, $preview->consideration, VatReturnService::COUNTRY, $preview->edition,
            hash('sha256', 'rule file'), $mapping, $form->payable, $form->postedTax, $form->roundingDifference(),
            $entryKey === null ? null : $entryByKey($em, $entryKey), $filedOn === null ? null : day($filedOn),
            'harness', day('2026-04-10 09:30:00'), hash('sha256', "figures {$revision}"), $warnings === [] ? null : hash('sha256', implode('|', $warnings)), $warnings,
            sprintf('data/archive/financial/vat-return/2026/mwst-abrechnung-%s-%s-r%d.pdf', $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $revision),
            hash('sha256', "pdf {$revision}"), sprintf('mwst-abrechnung-%s-r%d.pdf', $period->from->format('Y-m-d'), $revision));
        $em->persist($return);
        foreach ($preview->fields as $f) {
            $em->persist(new VatReturnFigure($return, $f->field, $f->kind, $f->value, $f->base, $f->lines));
        }
        $em->flush();
        $em->persist(new VatReturnLog($return, VatReturnLogAction::Post, 'harness', day('2026-04-10 09:30:00'), null, $warnings === [] ? [] : ['warnings' => $warnings]));

        return (int) $return->getId();
    });
};
$y2026 = static fn() => $wire()->getRepository(FiscalYear::class)->findOneBy(['code' => '2026']);
$span  = static fn(?VatPeriod $p): string => $p === null ? 'none' : $p->from->format('Y-m-d') . '..' . $p->to->format('Y-m-d');

// The writers of the sections F and S.
/** Runs $fn(LedgerService, em) in a unit of work of a fresh request — the way a module posts. */
$inLedger = static function (callable $fn) use ($wire): mixed {
    $em = $wire();

    return $em->getTransaction(JournalEntry::class)->run(static fn() => $fn(new LedgerService($em, 'harness'), $em));
};
/** 'ok', or the reason of the posting / editing refusal $fn raised, or «class: message» of anything else. */
$outcome = static function (callable $fn): string {
    try {
        $fn();
    } catch (PostingRefusedException | EntryNotEditableException $e) {
        return $e->reason;
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'ok';
};
$gTax = static fn(string $date, string $key, string $text = 'F Verkauf'): PostingRequest => PostingRequest::generated(day($date), $text, 'harness', $key, $key, [
    PostingLine::debit('1100', chf('108.10')),
    PostingLine::credit('3200', chf('100.00'), 'Ware', 'UN', 810, chf('100.00'), chf('8.10')),
    PostingLine::credit('2200', chf('8.10'), 'MWST UN'),
]);
$gPlain = static fn(string $date, string $key, string $text = 'F Umbuchung'): PostingRequest => PostingRequest::generated(day($date), $text, 'harness', $key, $key, [
    PostingLine::debit('1000', chf('50.00')), PostingLine::credit('1020', chf('50.00')),
]);
$mTax = static fn(string $date, string $text = 'F Spesen'): PostingRequest => PostingRequest::manual(day($date), $text, [
    PostingLine::debit('6500', chf('200.00'), null, 'VM', 810, chf('200.00'), chf('16.20')), PostingLine::debit('1170', chf('16.20')), PostingLine::credit('1020', chf('216.20')),
]);
$mPlain = static fn(string $date, string $text = 'F Umbuchung von Hand'): PostingRequest => PostingRequest::manual(day($date), $text, [
    PostingLine::debit('1000', chf('30.00')), PostingLine::credit('1020', chf('30.00')),
]);
$manual = static fn(): ManualEntryService => new ManualEntryService($wire(), 'harness');
/** id and current version of a manual entry — what its form carries. */
$at     = static fn(EntryRef $ref): array => array_map('intval', array_values($db->fetchAssociative('SELECT id, version FROM journal_entry WHERE number = ?', [$ref->number])));
$refOf  = static fn(string $key): EntryRef => new EntryRef('2026', (int) $db->fetchOne('SELECT number FROM journal_entry WHERE idempotency_key = ?', [$key]));
$rangeAt = static fn(): int => (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'journal-entry.2026'");
/** What a refusal must leave untouched: entries, lines, change rows, the number range. */
$books  = static fn(): array => [$count('journal_entry'), $count('journal_line'), $count('journal_entry_change'), $rangeAt()];
$post1  = static fn(PostingRequest $r) => $inLedger(static fn(LedgerService $l) => $l->post($r));
$amend  = static fn(string $key, PostingRequest $r) => $inLedger(static fn(LedgerService $l) => $l->amend($key, $r));
$retract = static fn(string $key) => $inLedger(static fn(LedgerService $l) => $l->retract($key, 'harness'));
$reverse = static fn(string $key, string $date, string $reason) => $inLedger(static fn(LedgerService $l) => $l->reverse($refOf($key), day($date), $reason));
$only    = static fn(array $outcomes, string $expected): string => implode(', ', array_keys(array_filter($outcomes, static fn(string $o): bool => $o !== $expected)));

// The writers of the sections P, G and R — the real «MWST buchen» (slice 5a). The settings come from the mandator record.
/** The posting service on a fresh request; the registry empty unless given. */
$poster = static fn(?OpenWorkChecks $checks = null): VatReturnPostingService => new VatReturnPostingService($wire(), 'harness', $checks ?? new OpenWorkChecks([]));
/** Quarter $n of $year. */
$qOf = static fn(int $year, int $n): VatPeriod => new VatPeriod(day(sprintf('%d-%02d-01', $year, $n * 3 - 2)), day(sprintf('%d-%02d-01', $year, $n * 3 - 2))->modify('+3 months -1 day'), VatRhythm::Quarter);
/** plan(), then post() with exactly what it showed — the confirm dialog's round trip. */
$postReturn = static function (VatPeriod $p, string $today, ?string $filed = null, ?OpenWorkChecks $checks = null) use ($poster): VatReturn {
    $plan = $poster($checks)->plan($p, day($today));

    return $poster($checks)->post($p, $plan->figuresFingerprint, $plan->warningsFingerprint, $filed === null ? null : day($filed), day($today));
};
/** 'ok', or the stable reason of whatever refusal $fn raised, or «class: message». */
$why = static function (callable $fn): string {
    try {
        $fn();
    } catch (VatReturnRefusedException $e) {
        return $e->refusal->reason;
    } catch (PostingRefusedException | EntryNotEditableException | FiscalYearCloseRefusedException $e) {
        return $e->reason;
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'ok';
};
/** A sale: Dr 1100 gross / Cr 3200 net (code, base, tax) / Cr 2200 tax. */
$sale = static fn(string $date, string $key, string $net, string $tax, string $code = 'UN'): PostingRequest => PostingRequest::generated(day($date), "Rechnung {$key}", 'harness', $key, $key, [
    PostingLine::debit('1100', chf($net)->add(chf($tax))),
    PostingLine::credit('3200', chf($net), 'Ware', $code, 810, chf($net), chf($tax)),
    PostingLine::credit('2200', chf($tax), 'MWST ' . $code),
]);
/** A purchase: Dr $account net (code, base, tax) / Dr $vat tax / Cr 1020 gross. */
$buy = static fn(string $date, string $key, string $account, string $vat, string $code, string $net, string $tax): PostingRequest => PostingRequest::generated(day($date), "Einkauf {$key}", 'harness', $key, $key, [
    PostingLine::debit($account, chf($net), 'Einkauf', $code, 810, chf($net), chf($tax)),
    PostingLine::debit($vat, chf($tax), 'Vorsteuer ' . $code),
    PostingLine::credit('1020', chf($net)->add(chf($tax))),
]);
/** The lines of the entry with key $key: «account D|C amount», in posting order. */
$linesOf = static fn(string $key): array => array_map(static fn(array $r): string => $r['number'] . ' ' . ((float) $r['debit'] > 0 ? 'D ' . $r['debit'] : 'C ' . $r['credit']),
    $db->fetchAllAssociative('SELECT a.number, l.debit, l.credit FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id WHERE e.idempotency_key = ? ORDER BY l.id', [$key]));
/** The vouchers in the archive. */
$vouchers = static fn(): array => glob($base . '/' . VatReturnArchive::DIR . '/*/*.pdf') ?: [];
$fyId = static fn(string $code): int => (int) $db->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$code]);
/** Closes the fiscal year $code through the real service and the registered checks, confirming exactly the warnings it shows. */
$closeYear = static function (string $code) use ($wire, $fyId): void {
    $em   = $wire();
    $year = $em->getRepository(FiscalYear::class)->find($fyId($code));
    $fp   = FiscalYearCloseService::warningsFingerprint((new FiscalYearCloseService($em, 'harness'))->closeCheck($year));
    (new FiscalYearCloseService($wire(), 'harness'))->close($fyId($code), $fp);
};
/** The findings of the year-close check of financial for one year: «B|W message @reference». */
$closeFindings = static fn(string $code, string $from, string $to): array => array_map(
    static fn(\Z77\Persistence\Doctrine\OpenWork\Finding $f): string => ($f->severity === \Z77\Persistence\Doctrine\OpenWork\Severity::Blocking ? 'B ' : 'W ') . $f->message . ' @' . $f->reference,
    iterator_to_array((new UnsettledVatPeriodsCheck($wire()))->check('period-close', ['fiscalYear' => $code, 'from' => day($from), 'to' => day($to)]), false));
/** id and version of a manual entry of the fiscal year $code. */
$atIn = static fn(EntryRef $ref): array => array_map('intval', array_values($db->fetchAssociative(
    'SELECT e.id, e.version FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE y.code = ? AND e.number = ?', [$ref->fiscalYear, $ref->number])));
/** The books of the fiscal year $code: entries, lines, change rows, the number range — what a refusal leaves untouched — and the returns. */
$booksOf = static fn(string $code): array => [$count('journal_entry'), $count('journal_line'), $count('journal_entry_change'),
    (int) $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', ['journal-entry.' . $code]), $count('vat_return'), $count('vat_return_figure'), $count('vat_return_log')];
/**
 * Undoes a posted return of the quarter from $from BY HAND (section R reuses one period; there is no lift before slice 6): the
 * rows, the settlement entry, and its number when it is the range's last.
 */
$undoReturn = static function (string $from) use ($db): void {
    foreach ($db->fetchAllAssociative("SELECT r.id, r.settlement_entry_id, e.number, y.code FROM vat_return r LEFT JOIN journal_entry e ON e.id = r.settlement_entry_id LEFT JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE r.kind = 'ordinary' AND r.period_from = ?", [$from]) as $row) {
        $db->executeStatement('DELETE FROM vat_return_figure WHERE vat_return_id = ?', [$row['id']]);
        $db->executeStatement('DELETE FROM vat_return_log WHERE vat_return_id = ?', [$row['id']]);
        $db->executeStatement('DELETE FROM vat_return WHERE id = ?', [$row['id']]);
        if ($row['settlement_entry_id'] !== null) {
            $db->executeStatement('DELETE FROM journal_line WHERE entry_id = ?', [$row['settlement_entry_id']]);
            $db->executeStatement('DELETE FROM journal_entry WHERE id = ?', [$row['settlement_entry_id']]);
            $db->executeStatement('UPDATE number_range SET last_number = last_number - 1 WHERE name = ? AND last_number = ?', ['journal-entry.' . $row['code'], $row['number']]);
        }
    }
};
/**
 * Section R: starts a worker (`--worker {base} {args…}`), waits until it holds, runs $write here — it must WAIT for the worker —,
 * and answers [the worker's output, $write's outcome, whether the worker got to its hold].
 */
$inFlight = static function (array $args, callable $write) use ($base, $why): array {
    $mode = $args[0];
    @unlink("{$base}/{$mode}.ready");
    $worker = proc_open([PHP_BINARY, __FILE__, '--worker', $base, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($t = 0; $t < 300 && !is_file("{$base}/{$mode}.ready"); $t++) { usleep(100000); }
    $held   = is_file("{$base}/{$mode}.ready");
    $result = $held ? $why($write) : 'worker never held';
    $out    = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]); proc_close($worker);

    return [$out, $result, $held];
};

// `--from=A` / `--from=F` / `--from=S`: the fresh schema and installation take the saved state, and the run jumps to the section.
if ($fromSection !== null) {
    echo "Checkpoint run: from section {$fromSection}\n";
    extract($checkpoint->restore($fromSection, $db, $base));
    $em = $wire();
    switch ($fromSection) {
        case 'A': goto section_A;
        case 'F': goto section_F;
        case 'S': goto section_S;
        case 'P': goto section_P;
        case 'G': goto section_G;
        case 'R': goto section_R;
    }
}

$run = static function (array $input): array {
    $app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
        \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
        \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
        \Z77\Core\DI::getCacheManager()->generatedPhp(),
        \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
    );
    $app->setAutoExit(false);
    $out  = new \Symfony\Component\Console\Output\BufferedOutput();
    $code = $app->run(new \Symfony\Component\Console\Input\ArrayInput($input + ['--no-interaction' => true]), $out);

    return [$code, $out->fetch()];
};
[$code, $out] = $run(['command' => 'migrate']);
check('M1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($out));

$migration = 'Z77\\Module\\Financial\\Migrations\\Version20261124182954';
$columns   = static fn(string $table): array => array_map('strval', $db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$dbName, $table]));
$nullable  = static function (string $table) use ($db, $dbName): array {
    $names = array_map('strval', $db->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND IS_NULLABLE = 'YES'", [$dbName, $table]));
    sort($names, SORT_STRING);   // byte order — the collation sorts «lift_reason» after «lifted_by»

    return $names;
};
$type      = static fn(string $table, string $column): string => strtolower((string) $db->fetchOne('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, $table, $column]));
$indexes   = static function (string $table) use ($db, $dbName): array {
    $out = [];
    foreach ($db->fetchAllAssociative('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$dbName, $table]) as $row) {
        $key = ((int) $row['NON_UNIQUE'] === 0 ? 'U ' : 'I ') . $row['INDEX_NAME'];
        $out[$key] = isset($out[$key]) ? $out[$key] . ',' . $row['COLUMN_NAME'] : $row['COLUMN_NAME'];
    }

    return $out;
};
$fks = static fn(string $table): array => array_map('strval', $db->fetchFirstColumn('SELECT CONCAT(COLUMN_NAME, \'>\', REFERENCED_TABLE_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, $table]));
$vatTables = static fn(): array => array_map('strval', $db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'vat\\_return%' ORDER BY 1", [$dbName]));

$engines = $db->fetchAllKeyValue("SELECT TABLE_NAME, CONCAT(ENGINE, ' ', TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'vat\\_return%' ORDER BY 1", [$dbName]);
check('M2 the three tables exist — vat_return, vat_return_figure, vat_return_log — InnoDB, utf8mb4_unicode_ci (the database default is general_ci), the migration recorded',
    $engines === ['vat_return' => 'InnoDB utf8mb4_unicode_ci', 'vat_return_figure' => 'InnoDB utf8mb4_unicode_ci', 'vat_return_log' => 'InnoDB utf8mb4_unicode_ci']
    && in_array($migration, $db->fetchFirstColumn('SELECT version FROM schema_migration'), true), json_encode($engines));
check('M3 vat_return carries the columns of the plan §1.1 plus `kind` (O11), `write_count` (DOCTRINE-VERSION-001) — and NO channel, corrected_from / corrected_to, replaces_id (stage S3 adds what it needs)',
    $columns('vat_return') === ['id', 'kind', 'period_from', 'period_to', 'revision', 'state', 'live', 'rhythm', 'method', 'consideration', 'country', 'form_edition', 'mapping_hash',
        'mapping_snapshot', 'currency', 'payable', 'posted_tax', 'rounding_difference', 'filed_on', 'posted_by', 'posted_at', 'lifted_by', 'lifted_at', 'lift_reason',
        'figures_fingerprint', 'warnings_fingerprint', 'confirmed_warnings', 'voucher_path', 'voucher_sha256', 'voucher_name', 'version', 'write_count',
        'settlement_entry_id', 'reversal_entry_id']
    && $columns('vat_return_figure') === ['id', 'field', 'kind', 'value_exact', 'value_rounded', 'base', 'line_count', 'vat_return_id']
    && $columns('vat_return_log') === ['id', 'vat_return_id', 'period_from', 'period_to', 'revision', 'action', 'actor', 'acted_at', 'reason', 'detail'],
    json_encode([$columns('vat_return'), $columns('vat_return_figure'), $columns('vat_return_log')]));
check('M4 nullable are exactly: on vat_return the settlement entry (a NIL return has none), the reversal, live, the filed date, the lift, the warnings — on the figure only the base — on the log the reason and the detail',
    $nullable('vat_return') === ['confirmed_warnings', 'filed_on', 'lift_reason', 'lifted_at', 'lifted_by', 'live', 'reversal_entry_id', 'settlement_entry_id', 'warnings_fingerprint']
    && $nullable('vat_return_figure') === ['base'] && $nullable('vat_return_log') === ['detail', 'reason'],
    json_encode([$nullable('vat_return'), $nullable('vat_return_figure'), $nullable('vat_return_log')]));
check('M5 types: the exact figure DECIMAL(21,6), every amount DECIMAL(15,2), `live` TINYINT, the hashes CHAR(64), country CHAR(2), currency CHAR(3), the snapshot and the warnings text',
    $type('vat_return_figure', 'value_exact') === 'decimal(21,6)' && $type('vat_return_figure', 'value_rounded') === 'decimal(15,2)' && $type('vat_return_figure', 'base') === 'decimal(15,2)'
    && $type('vat_return', 'payable') === 'decimal(15,2)' && $type('vat_return', 'posted_tax') === 'decimal(15,2)' && $type('vat_return', 'rounding_difference') === 'decimal(15,2)'
    && str_starts_with($type('vat_return', 'live'), 'tinyint') && $type('vat_return', 'mapping_hash') === 'char(64)' && $type('vat_return', 'figures_fingerprint') === 'char(64)'
    && $type('vat_return', 'voucher_sha256') === 'char(64)' && $type('vat_return', 'country') === 'char(2)' && $type('vat_return', 'currency') === 'char(3)'
    && str_ends_with($type('vat_return', 'mapping_snapshot'), 'text') && str_ends_with($type('vat_return', 'confirmed_warnings'), 'text'));
$ix = $indexes('vat_return');
check('M6 indexes: UNIQUE (kind, period_from, revision), UNIQUE (kind, period_from, live) — `kind` LEADS both (O11) —, INDEX (kind, live, period_to) for «settled» and «the latest»; the figure UNIQUE (vat_return_id, field); the log indexed by return',
    ($ix['U ' . VatReturn::UNIQUE_REVISION] ?? '') === 'kind,period_from,revision' && ($ix['U ' . VatReturn::UNIQUE_LIVE] ?? '') === 'kind,period_from,live'
    && ($ix['I idx_vat_return_live_to'] ?? '') === 'kind,live,period_to' && ($indexes('vat_return_figure')['U ' . VatReturnFigure::UNIQUE_FIELD] ?? '') === 'vat_return_id,field'
    && ($indexes('vat_return_log')['I idx_vat_return_log_return'] ?? '') === 'vat_return_id', json_encode([$ix, $indexes('vat_return_figure'), $indexes('vat_return_log')]));
check('M7 foreign keys: vat_return → journal_entry twice (settlement, reversal), vat_return_figure → vat_return; the protocol has NONE (it outlives nothing, the close log\'s model)',
    $fks('vat_return') === ['reversal_entry_id>journal_entry', 'settlement_entry_id>journal_entry'] && $fks('vat_return_figure') === ['vat_return_id>vat_return'] && $fks('vat_return_log') === []);
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Financial\\Migrations']);
check('M8 diff after migrate reports NO change — the three entities and the migration agree', $code !== 0 && str_contains($out, 'No changes detected'), trim($out));

// The unique indexes at work — raw rows in a rolled-back transaction each.
$liveTwice = $probe(fn() => $violation(function () use ($rawReturn): void { $rawReturn(); $rawReturn(['revision' => 2]); }));
$liftedBeside = $probe(fn() => $violation(function () use ($rawReturn): void {
    $rawReturn(['state' => 'lifted', 'live' => null]); $rawReturn(['state' => 'lifted', 'live' => null, 'revision' => 2]); $rawReturn(['revision' => 3]);
}));
$revisionTwice = $probe(fn() => $violation(function () use ($rawReturn): void { $rawReturn(['state' => 'lifted', 'live' => null]); $rawReturn(['state' => 'lifted', 'live' => null]); }));
$kindsBeside = $probe(fn() => $violation(function () use ($rawReturn): void {
    $rawReturn(); $rawReturn(['kind' => 'correction']); $rawReturn(['kind' => 'annual', 'period_to' => '2026-12-31', 'rhythm' => 'year']);
}));
$figureTwice = $probe(fn() => $violation(function () use ($rawReturn, $db): void {
    $id = $rawReturn();
    foreach ([1, 2] as $_) {
        $db->insert('vat_return_figure', ['vat_return_id' => $id, 'field' => '200', 'kind' => 'total', 'value_exact' => '0.000000', 'value_rounded' => '0.00', 'line_count' => 0]);
    }
}));
check('M9 the unique indexes: a SECOND live return of the same kind and period is refused (uniq_vat_return_live — at most one posted return per kind + period); two lifted rows (live NULL) and a live revision 3 beside them are accepted; the same kind + period + revision twice is refused; a correction and an annual row beside the live ordinary one (same period_from) are accepted — `kind` leads; a field declared twice on one return is refused',
    str_contains($liveTwice, VatReturn::UNIQUE_LIVE) && $liftedBeside === '' && str_contains($revisionTwice, VatReturn::UNIQUE_REVISION) && $kindsBeside === ''
    && str_contains($figureTwice, VatReturnFigure::UNIQUE_FIELD) && $count('vat_return') === 0,
    json_encode([$liveTwice, $liftedBeside, $revisionTwice, $kindsBeside, $figureTwice]));

// down() / up() of the migration: BY NAME first (a newer one of another module may follow), then `prev` runs exactly its down().
$run(['command' => 'migrate', 'version' => $migration]);
[$downCode, $downOut] = $run(['command' => 'migrate', 'version' => 'prev']);
$afterDown = $vatTables();
[$upCode, $upOut] = $run(['command' => 'migrate']);
check('M10 down() drops the three tables (figure first — its key points at the return), the journal untouched; up() again creates them',
    $downCode === 0 && $afterDown === [] && in_array('journal_entry', array_map('strval', $db->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$dbName])), true)
    && $upCode === 0 && $vatTables() === ['vat_return', 'vat_return_figure', 'vat_return_log'], trim($downOut) . ' / ' . trim($upOut));

// The books: KMU chart, fiscal year 2026, a liable mandator; a coded invoice in Q1 (a tax with a sub-Rappen exact
// value); the settlement-like entries the rows of A point at (source `vat-return`, left out of the reconciliation).
$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$post(PostingRequest::generated(day('2026-02-10'), 'Rechnung 1 · Beispiel AG', 'invoice', '1', 'invoice:1:final', [
    PostingLine::debit('1100', chf('1080.99')),
    PostingLine::credit('3200', chf('999.99'), 'Ware', 'UN', 810, chf('999.99'), chf('81.00')),
    PostingLine::credit('2200', chf('81.00'), 'MWST UN'),
]));
$settle = static fn(int $revision) => PostingRequest::generated(day('2026-03-31'), 'MWST-Abrechnung 1. Quartal 2026', VatReturnService::SOURCE_TYPE, "2026-01-01/2026-03-31/r{$revision}",
    "vat-return:2026-01-01:2026-03-31:r{$revision}", [PostingLine::debit('2200', chf('81.00')), PostingLine::credit('2201', chf('81.00'))]);
$r1Ref = $post($settle(1));
$emRev = $wire();
$emRev->getTransaction(JournalEntry::class)->run(fn() => (new LedgerService($emRev, 'harness'))->reverse($r1Ref, day('2026-03-31'), 'Aufgehoben: MWST-Abrechnung 1. Quartal 2026'));
$post($settle(2));
check('M11 the books: KMU chart (2200, 2201), fiscal year 2026, the mandator liable (quarter from 1.1.2026); one coded invoice in Q1; the settlement entries r1, its reversal and r2 of source vat-return; no return row yet',
    $count('journal_entry') === 4 && $count('journal_entry', "source_type = 'vat-return'") === 3 && $count('vat_return') === 0
    && $service()->settingsRefusal() === null);

$checkpoint->save('A', $db, $base, []);
section_A:
// ─────────────────────────────────────────────────────────────────────────
echo "A. Rows inserted by the harness — settled, lifted, revision, latest — and what the service reports\n";

$q1 = $quarter(1);
$q2 = $quarter(2);
$q3 = $quarter(3);
$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
check('A1 no return: nothing is settled — Q1 open, «Nächste Abrechnung» Q1 on 5.10.2026, the list all «offen» with no posted and no lifted return, the preview «offen», no latest return',
    !$svc->isSettled($q1) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-01-01..2026-03-31'
    && array_map(static fn($s) => $s->state . '/' . $s->stateLabel, $sum) === array_fill(0, 4, 'open/offen')
    && array_filter($sum, static fn($s) => $s->posted !== null || $s->lifted !== null) === []
    && $svc->preview($q1)->state === VatReturnPreview::STATE_OPEN && $svc->preview($q1)->posted === null && $svc->settlement()->latest() === null
    && $svc->settlement()->liveReturns() === []);

$q1Payable = $service()->preview($q1)->payable();
$r1 = $persistReturn($q1, 1, 'vat-return:2026-01-01:2026-03-31:r1', null, ['Ertragskonto «3400 Dienstleistungsertrag»: 1 Buchung ohne MWST-Code']);
$editionFields = count($service()->preview($q1)->fields);
check('A2 Q1 posted (revision 1, the settlement entry r1): one row — ordinary, posted, live = 1 —, one figure per field of the edition (' . $editionFields . '), one protocol row «post» with the confirmed warning in its detail',
    $count('vat_return') === 1 && $db->fetchAssociative('SELECT kind, state, live, revision FROM vat_return WHERE id = ?', [$r1]) === ['kind' => 'ordinary', 'state' => 'posted', 'live' => 1, 'revision' => 1]
    && $count('vat_return_figure', "vat_return_id = {$r1}") === $editionFields && $editionFields > 10
    && $db->fetchAssociative('SELECT action, actor, reason FROM vat_return_log WHERE vat_return_id = ?', [$r1]) === ['action' => 'post', 'actor' => 'harness', 'reason' => null]
    && json_decode((string) $db->fetchOne('SELECT detail FROM vat_return_log WHERE vat_return_id = ?', [$r1]), true) === ['warnings' => ['Ertragskonto «3400 Dienstleistungsertrag»: 1 Buchung ohne MWST-Code']]);

$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
$set = $svc->settlement();
check('A3 settled is READ from the row: Q1 settled, Q2 not; «Nächste Abrechnung» Q2; the list shows Q1 «gebucht» with the DECLARED payable and its return, Q2–Q4 «offen»; the preview of Q1 «gebucht» naming the return; VatSettlement: the period covering 1.1. and 31.3. is Q1 (not short), 1.4. none; the latest return is r1',
    $svc->isSettled($q1) && !$svc->isSettled($q2) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-04-01..2026-06-30'
    && $sum[0]->state === VatReturnPreview::STATE_POSTED && $sum[0]->stateLabel === 'gebucht' && $sum[0]->posted?->getId() === $r1 && $sum[0]->lifted === null
    && $sum[0]->payable?->equals($q1Payable) && $sum[0]->refusal === null
    && array_map(static fn($s) => $s->stateLabel, array_slice($sum, 1)) === ['offen', 'offen', 'offen']
    && $svc->preview($q1)->state === VatReturnPreview::STATE_POSTED && $svc->preview($q1)->stateLabel() === 'gebucht' && $svc->preview($q1)->posted?->getId() === $r1
    && $span($set->periodCovering(day('2026-01-01'))) === '2026-01-01..2026-03-31' && $set->periodCovering(day('2026-03-31'))?->short === false
    && $set->periodCovering(day('2026-04-01')) === null && $set->isSettled(day('2026-02-15')) && $set->latest()?->getId() === $r1,
    falseOf(['settled' => $svc->isSettled($q1), 'next' => $span($svc->nextReturn(day('2026-10-05'))) === '2026-04-01..2026-06-30', 'list' => $sum[0]->stateLabel === 'gebucht', 'payable' => (bool) $sum[0]->payable?->equals($q1Payable)]));

$preview = $service()->preview($q1);
$emRead  = $wire();
$read    = $emRead->getRepository(VatReturn::class)->find($r1);
$figures = $emRead->getRepository(VatReturn::class)->figuresOf($read);
$exactOk = count($figures) === count($preview->fields);
foreach ($preview->fields as $i => $f) {
    $g = $figures[$i] ?? null;
    $exactOk = $exactOk && $g !== null && $g->getField() === $f->field && $g->getKind() === $f->kind && $g->getValueExact()->equals($f->value)
        && $g->getValueRounded()->equals($f->rounded) && (($g->getBase() === null && $f->base === null) || ($g->getBase()?->equals($f->base) ?? false)) && $g->getLineCount() === $f->lines;
}
$rateField = array_values(array_filter($preview->fields, static fn($f) => $f->kind === FieldKind::Rate && !$f->value->isZero()))[0] ?? null;
check('A4 the row reads back: the repository is VatReturnRepository; the period, settings, edition, the resolved mapping (code UN at 8.1 %), the payable / posted tax / rounding difference as computed, the settlement entry r1, the warnings; every figure in form order equals the preview field — EXACT (the rate field ' . ($rateField?->field ?? '?') . ' stored as ' . ($rateField === null ? '?' : $db->fetchOne('SELECT value_exact FROM vat_return_figure WHERE vat_return_id = ? AND field = ?', [$r1, $rateField->field])) . ', a fraction of a Rappen kept), rounded, base, line count',
    $emRead->getRepository(VatReturn::class) instanceof VatReturnRepository && $read instanceof VatReturn
    && $read->period()->equals($q1) && $read->getMethod() === VatMethod::Effective && $read->getConsideration() === VatConsideration::Agreed && $read->getRhythm() === VatRhythm::Quarter
    && $read->getFormEdition() === $preview->edition && $read->getCountry() === 'CH' && $read->getCurrency() === 'CHF'
    && in_array(['code' => 'UN', 'category' => 'standard', 'rate' => 810], array_map(static fn(array $a) => array_intersect_key($a, ['code' => 1, 'category' => 1, 'rate' => 1]), $read->getMappingSnapshot()), true)
    && $read->getPayable()->equals($preview->form->payable) && $read->getPostedTax()->equals($preview->form->postedTax) && $read->getRoundingDifference()->equals($preview->form->roundingDifference())
    && $read->getSettlementEntry()?->getIdempotencyKey() === 'vat-return:2026-01-01:2026-03-31:r1' && $read->getVersion() === 1
    && $read->getConfirmedWarnings() === ['Ertragskonto «3400 Dienstleistungsertrag»: 1 Buchung ohne MWST-Code'] && $exactOk
    && $rateField !== null && $db->fetchOne('SELECT value_exact FROM vat_return_figure WHERE vat_return_id = ? AND field = ?', [$r1, $rateField->field]) === $rateField->value->toDecimal()
    && !$rateField->value->isWhole());

$facts = static function (VatReturnPreview $p): string {
    $rows = (new \ReflectionMethod(VatReturnPdf::class, 'facts'))->invoke(null, $p, '1. Quartal 2026', '01.01.–31.03.2026', null)['rows'];
    foreach ($rows as $row) {
        if ($row['cells'][0] === 'Zustand') {
            return $row['cells'][1];
        }
    }

    return '';
};
check('A5 the PDF names the state of a posted period: «gebucht am 10.04.2026 von harness» (an open one keeps «offen — nicht gebucht»)',
    $facts($service()->preview($q1)) === 'gebucht am 10.04.2026 von harness' && $facts($service()->preview($q2)) === 'offen — nicht gebucht', $facts($service()->preview($q1)));

// Lift r1 — the entity transition, the reversal entry the ledger wrote, a protocol row.
$emLift = $wire();
$emLift->getTransaction(VatReturn::class)->run(function () use ($emLift, $r1, $entryByKey): void {
    $return = $emLift->getRepository(VatReturn::class)->find($r1);
    $return->lift($entryByKey($emLift, 'reversal:2026/' . $return->getSettlementEntry()->getNumber()), 'admin', day('2026-04-20 11:00:00'), 'Irrtümlich gebucht — Umsatz fehlte');
    $emLift->persist(new VatReturnLog($return, VatReturnLogAction::Lift, 'admin', day('2026-04-20 11:00:00'), 'Irrtümlich gebucht — Umsatz fehlte'));
});
$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
check('A6 Q1 lifted: the row stays — state lifted, live NULL, the reversal entry, who / when / why, its figures and its voucher —; Q1 is open again: not settled, «Nächste Abrechnung» Q1, the list «offen» with the note of the lifted r1, the payable computed from the journal (the settlement entries are no movement), the preview «offen»; no latest return; the protocol post + lift',
    $db->fetchAssociative('SELECT state, live, lifted_by, lift_reason, voucher_path IS NOT NULL AS voucher FROM vat_return WHERE id = ?', [$r1]) === ['state' => 'lifted', 'live' => null, 'lifted_by' => 'admin', 'lift_reason' => 'Irrtümlich gebucht — Umsatz fehlte', 'voucher' => 1]
    && (int) $db->fetchOne('SELECT reversal_entry_id FROM vat_return WHERE id = ?', [$r1]) === (int) $db->fetchOne("SELECT id FROM journal_entry WHERE idempotency_key LIKE 'reversal:%'")
    && $count('vat_return_figure', "vat_return_id = {$r1}") === $editionFields
    && !$svc->isSettled($q1) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-01-01..2026-03-31'
    && $sum[0]->state === VatReturnPreview::STATE_OPEN && $sum[0]->posted === null && $sum[0]->lifted?->getId() === $r1 && $sum[0]->lifted?->getLiftedBy() === 'admin'
    && $sum[0]->payable?->equals($q1Payable) && $svc->preview($q1)->state === VatReturnPreview::STATE_OPEN && $svc->settlement()->latest() === null
    && $db->fetchFirstColumn('SELECT CONCAT(action, \'/\', COALESCE(reason, \'-\')) FROM vat_return_log WHERE vat_return_id = ? ORDER BY id', [$r1]) === ['post/-', 'lift/Irrtümlich gebucht — Umsatz fehlte']);

$r2 = $persistReturn($q1, 2, 'vat-return:2026-01-01:2026-03-31:r2');
$svc = $service();
$sum = $svc->periods($y2026(), day('2026-10-05'));
check('A7 Q1 posted again as revision 2: settled again; the revisions of Q1 latest first (r2 live, r1 lifted); the list «gebucht» with r2 and no lifted note; the preview names r2, the PDF «(Neuerstellung 2)»',
    $svc->isSettled($q1) && array_map(static fn(VatReturn $r) => $r->getRevision() . ($r->isLive() ? ' live' : ' ' . $r->getState()->value), $svc->settlement()->revisionsOf($q1)) === ['2 live', '1 lifted']
    && $sum[0]->posted?->getId() === $r2 && $sum[0]->lifted === null && $svc->preview($q1)->posted?->getId() === $r2
    && $facts($svc->preview($q1)) === 'gebucht am 10.04.2026 von harness (Neuerstellung 2)' && $span($svc->nextReturn(day('2026-10-05'))) === '2026-04-01..2026-06-30');

$r3 = $persistReturn($q2, 1, null, '2026-07-15');
$svc = $service();
$set = $svc->settlement();
$emFile = $wire();
[$liftFiled, $versions] = $emFile->getTransaction(VatReturn::class)->run(function () use ($emFile, $r3): array {
    $return = $emFile->getRepository(VatReturn::class)->find($r3);
    $refused = thrown(fn() => $return->lift(null, 'admin', day('2026-07-20'), 'x')) instanceof \LogicException;
    $before  = $return->getVersion();
    $return->setFiledOn(day('2026-07-15'));   // the same date again — the write still counts (DOCTRINE-VERSION-001)

    return [$refused, $before];
});
check('A8 Q2 posted as a NIL return (no settlement entry) with «Eingereicht am» 15.7.: settled; the latest return is Q2, the live returns Q1 r2 + Q2 in period order; «Nächste Abrechnung» Q3; a lift is refused while the filed date is set; setting the same filed date again still moves the optimistic lock (1 → 2)',
    $svc->isSettled($q2) && $set->latest()?->getId() === $r3 && $set->latest()?->isNil() && $set->latest()?->getFiledOn()?->format('Y-m-d') === '2026-07-15'
    && array_map(static fn(VatReturn $r) => $r->getId(), $set->liveReturns()) === [$r2, $r3] && $span($svc->nextReturn(day('2026-10-05'))) === '2026-07-01..2026-09-30'
    && $liftFiled && $versions === 1 && (int) $db->fetchOne('SELECT version FROM vat_return WHERE id = ?', [$r3]) === 2
    && $db->fetchOne('SELECT settlement_entry_id FROM vat_return WHERE id = ?', [$r3]) === null);

$correction = $rawReturn(['kind' => VatReturnKind::Correction->value, 'period_from' => '2026-07-01', 'period_to' => '2026-09-30']);
$annual     = $rawReturn(['kind' => VatReturnKind::Annual->value, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'rhythm' => 'year']);
$svc = $service();
$onlyOrdinary = !$svc->isSettled($q3) && $span($svc->nextReturn(day('2026-10-05'))) === '2026-07-01..2026-09-30' && $svc->settlement()->periodCovering(day('2026-08-01')) === null
    && $svc->settlement()->latest()?->getId() === $r3 && count($svc->settlement()->liveReturns()) === 2;
$db->executeStatement('DELETE FROM vat_return WHERE id IN (?, ?)', [$correction, $annual]);
check('A9 only an ORDINARY return settles: a live correction row of Q3 and a live annual row of 2026 settle nothing — Q3 stays open and next, the latest stays Q2', $onlyOrdinary);

// The locking read: inside a unit of work, a plain read is the snapshot of its first read; a return committed
// meanwhile (another connection) is seen only by the locking read — the trap of plan §0.1.
$outside = thrown(fn() => (new VatSettlement($wire()))->lockedPeriodCovering(day('2026-08-01')));
$emLock  = $wire();
[$plainBefore, $plainAfter, $locked] = $emLock->getTransaction(VatReturn::class)->run(function () use ($emLock, $rawReturn): array {
    $before = (new VatSettlement($emLock))->isSettled(day('2026-08-01'));   // the first plain read fixes the snapshot
    $rawReturn(['period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'payable' => '12.30']);   // committed by the harness connection

    return [$before, (new VatSettlement($emLock))->isSettled(day('2026-08-01')), (new VatSettlement($emLock))->lockedPeriodCovering(day('2026-08-01'))];
});
$db->executeStatement("DELETE FROM vat_return WHERE period_from = '2026-07-01'");
check('A10 lockedPeriodCovering() refuses outside a unit of work; inside one it sees a return committed after the first read (Q3) while a plain read still answers from the snapshot (not settled)',
    $outside instanceof \LogicException && !$plainBefore && !$plainAfter && $span($locked) === '2026-07-01..2026-09-30' && $locked?->short === false);

$dup   = thrown(fn() => $persistReturn($q1, 3, null));
check('A11 the database is the backstop under the entity: a third Q1 return persisted while r2 is live is refused by uniq_vat_return_live; nothing is written',
    $dup instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException && str_contains($dup->getMessage(), VatReturn::UNIQUE_LIVE)
    && $count('vat_return') === 3 && $count('vat_return_figure') === 3 * $editionFields && $count('vat_return_log') === 4, $dup === null ? 'nothing thrown' : get_class($dup) . ': ' . $dup->getMessage());

$entryR2 = (int) $db->fetchOne("SELECT id FROM journal_entry WHERE idempotency_key = 'vat-return:2026-01-01:2026-03-31:r2'");
$fk      = $probe(fn() => $violation(function () use ($db, $entryR2): void {
    $db->executeStatement('DELETE FROM journal_line WHERE entry_id = ?', [$entryR2]);
    $db->executeStatement('DELETE FROM journal_entry WHERE id = ?', [$entryR2]);
}));
check('A12 the settlement entry of a posted return cannot be deleted under it — the foreign key refuses', $fk !== '' && $count('journal_entry', "id = {$entryR2}") === 1, $fk);

$checkpoint->save('F', $db, $base, []);
section_F:
// ─────────────────────────────────────────────────────────────────────────
echo "F. The freeze — post / amend / retract / update / delete / reverse × tax line / none × a settled / an open VAT period\n";

// From A: Q1 (revision 2) and Q2 (nil) are settled, Q3 and Q4 open. Q3 is the playground: its entries are
// written while it is open, then a live return row settles it; Q4 stays open (the other column of the matrix).

// The entries of the matrix — all written while Q3 and Q4 are open.
foreach (['amend', 'out', 'loses', 'retract', 'reverse', 'reverse-later'] as $k) { $post1($gTax('2026-08-10', "f:s-tax-{$k}")); }
foreach (['amend', 'gets-tax', 'retract', 'reverse'] as $k) { $post1($gPlain('2026-08-11', "f:s-plain-{$k}")); }
foreach (['into', 'amend', 'retract', 'reverse', 'r9-amend', 'r9-retract'] as $k) { $post1($gTax('2026-11-10', "f:o-tax-{$k}")); }
$sTaxUpd   = $manual()->create($mTax('2026-08-12', 'F s tax update'));
$sTaxOut   = $manual()->create($mTax('2026-08-12', 'F s tax out'));
$sTaxDel   = $manual()->create($mTax('2026-08-12', 'F s tax delete'));
$sPlainUpd = $manual()->create($mPlain('2026-08-13', 'F s plain update'));
$sPlainGet = $manual()->create($mPlain('2026-08-13', 'F s plain gets tax'));
$sPlainDel = $manual()->create($mPlain('2026-08-13', 'F s plain delete'));
$oTaxInto  = $manual()->create($mTax('2026-11-11', 'F o tax into'));
$oPlainInto = $manual()->create($mPlain('2026-11-11', 'F o plain into'));
$oTaxUpd   = $manual()->create($mTax('2026-11-12', 'F o tax update'));
$oTaxDel   = $manual()->create($mTax('2026-11-12', 'F o tax delete'));
$oTaxHeld  = $manual()->create($mTax('2026-11-12', 'F o tax held'));
check('F1 the entries of the matrix are in the books while Q3 and Q4 are open: 16 generated (10 in Q3, 6 in Q4) and 11 manual ones — a tax line is accepted in an unsettled period',
    $count('journal_entry', "idempotency_key LIKE 'f:%'") === 16 && $count('journal_entry', "kind = 'manual' AND text LIKE 'F %'") === 11
    && !(new VatSettlement($wire()))->isSettled(day('2026-08-10')) && !(new VatSettlement($wire()))->isSettled(day('2026-11-10')));

$q3Return = $rawReturn(['period_from' => '2026-07-01', 'period_to' => '2026-09-30']);   // Q3 is settled from here on
$before   = $books();
$caught   = thrown(fn() => $post1($gTax('2026-08-20', 'f:s-new-tax')));
$refusedSettled = [
    'post tax'              => $outcome(fn() => $post1($gTax('2026-08-20', 'f:s-new-tax'))),
    'reverse tax, dated in' => $outcome(fn() => $reverse('f:s-tax-reverse', '2026-08-25', 'F Storno im abgerechneten Quartal')),
    'amend tax (text)'      => $outcome(fn() => $amend('f:s-tax-amend', $gTax('2026-08-10', 'f:s-tax-amend', 'F Verkauf geändert'))),
    'amend tax out of it'   => $outcome(fn() => $amend('f:s-tax-out', $gTax('2026-11-15', 'f:s-tax-out'))),
    'amend tax into it'     => $outcome(fn() => $amend('f:o-tax-into', $gTax('2026-08-15', 'f:o-tax-into'))),
    'amend gets a tax line' => $outcome(fn() => $amend('f:s-plain-gets-tax', $gTax('2026-08-11', 'f:s-plain-gets-tax'))),
    'amend loses its tax'   => $outcome(fn() => $amend('f:s-tax-loses', $gPlain('2026-08-10', 'f:s-tax-loses'))),
    'retract tax'           => $outcome(fn() => $retract('f:s-tax-retract')),
    'update tax (text)'     => $outcome(fn() => $manual()->update(...[...$at($sTaxUpd), $mTax('2026-08-12', 'F geändert')])),
    'update tax out of it'  => $outcome(fn() => $manual()->update(...[...$at($sTaxOut), $mTax('2026-11-14', 'F s tax out')])),
    'update tax into it'    => $outcome(fn() => $manual()->update(...[...$at($oTaxInto), $mTax('2026-08-14', 'F o tax into')])),
    'update gets a tax line' => $outcome(fn() => $manual()->update(...[...$at($sPlainGet), $mTax('2026-08-13', 'F s plain gets tax')])),
    'delete tax'            => $outcome(fn() => $manual()->delete(...$at($sTaxDel))),
];
check('F2 SETTLED × TAX LINE — every operation is refused `vat-period-settled`: post, a reversal DATED in the period, amend (text, moved out, moved in, gets / loses a tax line), retract, manual update (text, out, in, gets a tax line), manual delete; nothing written — entries, lines, change rows and the number range as before',
    $only($refusedSettled, 'vat-period-settled') === '' && $books() === $before, $only($refusedSettled, 'vat-period-settled') . ' ' . json_encode([$before, $books()]));
check('F3 the refusal names the VAT period, not the month: reason `vat-period-settled` (the old key `period-vat-settled` is gone), the exception carries the period 1.7.–30.9.2026, the message its span; the period rows are all `open` — nothing is stored on them',
    $caught instanceof PostingRefusedException && $caught->reason === 'vat-period-settled' && PostingRefusedException::VAT_PERIOD_SETTLED === EntryNotEditableException::VAT_PERIOD_SETTLED
    && !defined(PostingRefusedException::class . '::PERIOD_VAT_SETTLED') && !defined(EntryNotEditableException::class . '::PERIOD_VAT_SETTLED')
    && $span($caught->vatPeriod) === '2026-07-01..2026-09-30' && str_contains($caught->getMessage(), '01.07.2026–30.09.2026')
    && $count('fiscal_period', "state <> 'open'") === 0);

$acceptedSettled = [
    'post plain'                    => $outcome(fn() => $post1($gPlain('2026-08-21', 'f:s-new-plain'))),
    'amend plain (date, text)'      => $outcome(fn() => $amend('f:s-plain-amend', $gPlain('2026-08-22', 'f:s-plain-amend', 'F Umbuchung geändert'))),
    'retract plain'                 => $outcome(fn() => $retract('f:s-plain-retract')),
    'reverse plain, dated in'       => $outcome(fn() => $reverse('f:s-plain-reverse', '2026-08-26', 'F Storno ohne MWST')),
    'reverse tax, dated in Q4'      => $outcome(fn() => $reverse('f:s-tax-reverse-later', '2026-11-20', 'F Storno in der laufenden Periode')),
    'update plain (date, text)'     => $outcome(fn() => $manual()->update(...[...$at($sPlainUpd), $mPlain('2026-08-23', 'F geändert')])),
    'update plain into it'          => $outcome(fn() => $manual()->update(...[...$at($oPlainInto), $mPlain('2026-08-24', 'F o plain into')])),
    'delete plain'                  => $outcome(fn() => $manual()->delete(...$at($sPlainDel))),
];
check('F4 SETTLED × NO TAX LINE — everything stays possible while the fiscal year is open: post, amend, retract, a reversal dated in the period, manual update (also moved INTO the period), manual delete; and a tax entry of the settled period is reversed with a date in the RUNNING period (the later event belongs there)',
    $only($acceptedSettled, 'ok') === '' && $db->fetchOne("SELECT entry_date FROM journal_entry WHERE idempotency_key = 'f:s-plain-amend'") === '2026-08-22'
    && $count('journal_entry', "idempotency_key = 'f:s-plain-retract'") === 0 && $count('journal_entry', "idempotency_key = 'reversal:2026/" . $refOf('f:s-tax-reverse-later')->number . "' AND entry_date = '2026-11-20'") === 1
    && $count('journal_entry', "text = 'F s plain delete'") === 0 && $db->fetchOne("SELECT entry_date FROM journal_entry WHERE text = 'F o plain into'") === '2026-08-24',
    $only($acceptedSettled, 'ok'));

$acceptedOpen = [
    'post tax'              => $outcome(fn() => $post1($gTax('2026-11-21', 'f:o-new-tax'))),
    'post plain'            => $outcome(fn() => $post1($gPlain('2026-11-21', 'f:o-new-plain'))),
    'amend tax'             => $outcome(fn() => $amend('f:o-tax-amend', $gTax('2026-11-22', 'f:o-tax-amend', 'F Verkauf geändert'))),
    'retract tax'           => $outcome(fn() => $retract('f:o-tax-retract')),
    'reverse tax'           => $outcome(fn() => $reverse('f:o-tax-reverse', '2026-11-23', 'F Storno offen')),
    'update tax'            => $outcome(fn() => $manual()->update(...[...$at($oTaxUpd), $mTax('2026-11-24', 'F o geändert')])),
    'delete tax'            => $outcome(fn() => $manual()->delete(...$at($oTaxDel))),
];
check('F5 OPEN — the same operations with a tax line pass in the unsettled Q4 beside the settled Q3: post, amend, retract, reverse, manual update, manual delete', $only($acceptedOpen, 'ok') === '', $only($acceptedOpen, 'ok'));

// Only a LIVE ORDINARY return freezes: lifted, Q3 takes a tax line again; a live correction row over Q4 freezes nothing.
$db->executeStatement("UPDATE vat_return SET state = 'lifted', live = NULL, lifted_by = 'admin', lifted_at = '2026-10-20 10:00:00', lift_reason = 'F' WHERE id = ?", [$q3Return]);
$afterLift  = $outcome(fn() => $post1($gTax('2026-08-27', 'f:s-after-lift')));
$correction = $rawReturn(['kind' => VatReturnKind::Correction->value, 'period_from' => '2026-10-01', 'period_to' => '2026-12-31']);
$underCorrection = $outcome(fn() => $post1($gTax('2026-11-26', 'f:o-under-correction')));
$db->executeStatement('DELETE FROM vat_return WHERE id = ?', [$correction]);
$db->executeStatement("UPDATE vat_return SET state = 'posted', live = 1, lifted_by = NULL, lifted_at = NULL, lift_reason = NULL WHERE id = ?", [$q3Return]);
check('F6 only a LIVE ORDINARY return freezes: once the Q3 return is lifted a tax line posts into Q3 again; a live CORRECTION row over Q4 freezes nothing; the Q3 return live again, Q3 refuses again',
    $afterLift === 'ok' && $underCorrection === 'ok' && $outcome(fn() => $post1($gTax('2026-08-28', 'f:s-again'))) === 'vat-period-settled');

$ledger = new LedgerService($wire(), 'harness');
check('F7 the public answer of financial, lock-free — LedgerService::periodRefusal(date, withTaxLine): `vat-period-settled` in Q1 / Q3 only WITH a tax line, null without and in Q4, `no-fiscal-year` for 2025; periodRefusalText() is the one German sentence and names the VAT period by its LABEL (looked up, or the one handed in) — VatPeriod::label(), which the screens\' VatReturnLayout::periodLabel() delegates to',
    $ledger->periodRefusal(day('2026-02-01'), true) === 'vat-period-settled' && $ledger->periodRefusal(day('2026-08-01'), true) === 'vat-period-settled'
    && $ledger->periodRefusal(day('2026-08-01'), false) === null && $ledger->periodRefusal(day('2026-11-01'), true) === null
    && $ledger->periodRefusal(day('2025-12-31'), true) === PostingRefusedException::NO_FISCAL_YEAR && $ledger->periodRefusal(day('2025-12-31'), false) === PostingRefusedException::NO_FISCAL_YEAR
    && $ledger->periodRefusalText('vat-period-settled', day('2026-02-01')) === 'Die MWST-Periode 1. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr'
    && $ledger->periodRefusalText('vat-period-settled', day('2026-11-01'), $quarter(3)) === 'Die MWST-Periode 3. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr'
    && (new VatPeriod(day('2026-07-01'), day('2026-07-31'), VatRhythm::Month))->label() === 'Juli 2026' && (new VatPeriod(day('2026-01-01'), day('2026-12-31'), VatRhythm::Year))->label() === 'Jahr 2026'
    && \Z77\Module\Financial\Ui\VatReturnLayout::periodLabel($quarter(3)) === '3. Quartal 2026'
    && $ledger->periodRefusalText('vat-period-settled', day('2026-11-01')) === 'Die MWST-Periode des 01.11.2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht mehr'
    && thrown(fn() => $ledger->periodRefusalText('account-unknown', day('2026-02-01'))) instanceof \InvalidArgumentException);

// VR-R9 — ONE process: the unit of work makes a plain read (its snapshot starts), the harness connection commits a return
// for Q4, then the ledger is asked. Only a LOCKING read can refuse — the lock-free check still answers «not settled».
$r9 = static function (callable $write) use ($wire, $rawReturn, $db): array {
    $em    = $wire();
    $plain = [];
    $error = null;
    try {
        $em->getTransaction(JournalEntry::class)->run(function () use ($em, $rawReturn, $write, &$plain): void {
            $plain[] = (new VatSettlement($em))->isSettled(day('2026-11-27'));                 // the first plain read fixes the snapshot
            $rawReturn(['period_from' => '2026-10-01', 'period_to' => '2026-12-31']);          // committed meanwhile, by another connection
            $plain[] = (new VatSettlement($em))->isSettled(day('2026-11-27'));                 // … the snapshot does not see it
            $plain[] = (new LedgerService($em, 'harness'))->periodRefusal(day('2026-11-27'), true);
            $write(new LedgerService($em, 'harness'));
        });
    } catch (\Throwable $e) {
        $error = $e;
    }
    $db->executeStatement("DELETE FROM vat_return WHERE kind = 'ordinary' AND period_from = '2026-10-01'");

    return [$plain, $error];
};
$before = $books();
$plainRef = null;
[$r9Plain, $r9Post] = $r9(function (LedgerService $l) use ($gTax, $gPlain, &$plainRef): void {
    $plainRef = $l->post($gPlain('2026-11-27', 'f:r9-plain'));   // no tax line: accepted, no read of the returns
    $l->post($gTax('2026-11-27', 'f:r9-tax'));
});
[, $r9Amend]   = $r9(fn(LedgerService $l) => $l->amend('f:o-tax-r9-amend', $gTax('2026-11-10', 'f:o-tax-r9-amend', 'F R9 geändert')));
[, $r9Retract] = $r9(fn(LedgerService $l) => $l->retract('f:o-tax-r9-retract', 'harness'));
check('VR-R9 the settled check under lock is a LOCKING read: in a unit of work whose snapshot predates a return committed meanwhile, the plain reads still answer «not settled» (also periodRefusal()) — post() with a tax line is refused `vat-period-settled` all the same (a line without tax passes before it), and so are amend() and retract(); the whole unit of work rolls back: no entry, no change row, the number range as before',
    $r9Plain === [false, false, null] && $plainRef instanceof EntryRef
    && $r9Post instanceof PostingRefusedException && $r9Post->reason === 'vat-period-settled' && $span($r9Post->vatPeriod) === '2026-10-01..2026-12-31'
    && $r9Amend instanceof EntryNotEditableException && $r9Amend->reason === 'vat-period-settled'
    && $r9Retract instanceof EntryNotEditableException && $r9Retract->reason === 'vat-period-settled'
    && $books() === $before && $count('journal_entry', "idempotency_key IN ('f:r9-plain', 'f:r9-tax')") === 0 && $count('journal_entry', "idempotency_key = 'f:o-tax-r9-retract'") === 1,
    json_encode([$r9Plain, $r9Post === null ? null : get_class($r9Post) . ' ' . $r9Post->getMessage(), $r9Amend === null ? null : get_class($r9Amend), $r9Retract === null ? null : get_class($r9Retract), $before, $books()]));

// VR-R2s / R3s / R4s — two processes. The worker stands in for «MWST buchen» of Q4 in flight (the locks of plan §2.1, an
// uncommitted return); the write here must WAIT for it and then meet the committed return on its locking read.
$againstReturnInFlight = static function (callable $write) use ($base, $db, $outcome): array {
    @unlink($base . '/holdreturn.ready');
    $worker = proc_open([PHP_BINARY, __FILE__, '--worker', $base, 'holdreturn', '2026', '2026-10-01', '2026-12-31'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($t = 0; $t < 300 && !is_file($base . '/holdreturn.ready'); $t++) { usleep(100000); }
    $uncommitted = (int) $db->fetchOne("SELECT COUNT(*) FROM vat_return WHERE kind = 'ordinary' AND period_from = '2026-10-01'");
    $result = $outcome($write);
    $out    = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]); proc_close($worker);
    $db->executeStatement("DELETE FROM vat_return WHERE kind = 'ordinary' AND period_from = '2026-10-01'");

    return [$out, $result, $uncommitted];
};
$before = $books();
$r2 = $againstReturnInFlight(fn() => $post1($gTax('2026-11-28', 'f:r2-tax')));
check('VR-R2s a return in flight (stand-in, uncommitted), then a tax posting into its period: the posting WAITED at the number range (worker: ' . $r2[0] . ') and was refused `vat-period-settled` (' . $r2[1] . ') — no deadlock, nothing written, the number went back',
    $r2 === ['ok waited', 'vat-period-settled', 0] && $books() === $before, json_encode([$r2, $before, $books()]));
$r4 = $againstReturnInFlight(fn() => $manual()->update(...[...$at($oTaxHeld), $mTax('2026-11-12', 'F o tax held — geändert')]));
check('VR-R4s a return in flight, then a manual edit of a tax entry in its period (no number range — it meets the return at the PERIOD row): it WAITED (worker: ' . $r4[0] . ') and was refused `vat-period-settled` (' . $r4[1] . '); the entry is unchanged, no change row',
    $r4 === ['ok waited', 'vat-period-settled', 0] && $books() === $before && $db->fetchOne('SELECT text FROM journal_entry WHERE number = ?', [$oTaxHeld->number]) === 'F o tax held', json_encode($r4));
$r3 = $againstReturnInFlight(fn() => $post1($gPlain('2026-11-28', 'f:r3-plain')));
check('VR-R3s a return in flight, then a posting WITHOUT a tax line: it waited (worker: ' . $r3[0] . ') and was accepted — a settled period takes it',
    $r3 === ['ok waited', 'ok', 0] && $count('journal_entry', "idempotency_key = 'f:r3-plain'") === 1, json_encode($r3));

// VR-R10 — source guards (the literal paths are this harness's coverage): the settled check that decides is asked AFTER
// the period's share lock, in every write of the ledger.
$bodyOf = static function (string $file, string $function) use ($root): string {
    $src   = (string) file_get_contents($root . '/' . $file);
    $start = strpos($src, 'function ' . $function . '(');
    if ($start === false) {
        return '';
    }
    $next = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $src, $m, PREG_OFFSET_CAPTURE, $start + 10) === 1 ? $m[0][1] : strlen($src);

    return substr($src, $start, $next - $start);
};
/** In $body: the LAST period lock comes before $call, and $call is there. */
$after = static function (string $body, string $call): bool {
    $lock = strrpos($body, 'lockedPeriodState(');
    $at   = strpos($body, $call);

    return $lock !== false && $at !== false && $lock < $at;
};
$ledgerFile = 'packages/module-financial/src/Services/LedgerService.php';
$manualFile = 'packages/module-financial/src/Services/ManualEntryService.php';
$rulesSrc   = (string) file_get_contents($root . '/packages/module-financial/src/Services/PostingRules.php');
$allSrc     = implode("\n", $sources);
check('VR-R10 source guards: in LedgerService (postInternal, amend, retract) and ManualEntryService (update, delete) the LOCKED settled check follows the last lockedPeriodState(); PostingRules asks VatSettlement::lockedPeriodCovering() for it and decides what is frozen in ONE place (vatFrozenDates) — the only other reader of a tax line there is the «Abrechnung ab» rule (vatStartCrossed, section S); no code in packages/*/src names PeriodState::VatSettled or the old reason key; creditor and receipt ask LedgerService::periodRefusal() and read no period state',
    $after($bodyOf($ledgerFile, 'postInternal'), 'assertVatAccepts($request, locked: true)')
    && $after($bodyOf($ledgerFile, 'amend'), 'assertVatAllowsChange($entry, $new, locked: true)')
    && $after($bodyOf($ledgerFile, 'retract'), 'assertVatAllowsChange($entry, null, locked: true)')
    && $after($bodyOf($manualFile, 'update'), 'assertVatAllowsChange($entry, $new, locked: true)')
    && $after($bodyOf($manualFile, 'delete'), 'assertVatAllowsChange($entry, null, locked: true)')
    && str_contains($rulesSrc, '$settlement->lockedPeriodCovering($date)') && substr_count($rulesSrc, 'self::vatFrozenDates(') === 2
    && substr_count($bodyOf('packages/module-financial/src/Services/PostingRules.php', 'vatFrozenDates'), 'hasTaxLine()') === 2
    && substr_count($bodyOf('packages/module-financial/src/Services/PostingRules.php', 'vatStartCrossed'), 'hasTaxLine()') === 2 && substr_count($rulesSrc, 'hasTaxLine()') === 4
    && !str_contains($allSrc, 'VatSettled') && !str_contains($allSrc, 'PERIOD_VAT_SETTLED') && !str_contains($allSrc, 'period-vat-settled')
    && array_filter(['packages/module-creditor/src/Services/CreditorJournalPostingService.php', 'packages/module-receipt/src/Services/ReceiptJournalPostingService.php'],
        static fn(string $f): bool => !str_contains($sources[$f] ?? '', '->periodRefusal(') || str_contains($sources[$f] ?? '', 'PeriodState') || str_contains($sources[$f] ?? '', '->getState()')) === []);

$checkpoint->save('S', $db, $base, []);
section_S:
// ─────────────────────────────────────────────────────────────────────────
echo "S. «Abrechnung ab» — no tax line before the liable mandator's day (plan §5.1, ADR-041 addendum point 3)\n";

// From F: the mandator is liable, «Abrechnung ab» 1.1.2026; Q4 has no live return. The entries of the matrix are written
// while 1.1.2026 is the day; then the day moves to 1.12.2026 — mid-Q4, the day a liability began (ADR-041 addendum
// point 3: a short first period). Nothing settles Q4: every refusal below is `before-vat-start`, never the freeze.
$setStart = static fn(?string $day): int => (int) $db->executeStatement('UPDATE mandator SET vat_settlement_from = ?', [$day]);
$setStart('2026-01-01');
foreach (['amend', 'later', 'earlier', 'loses', 'retract', 'reverse-before', 'reverse-after'] as $k) { $post1($gTax('2026-11-10', "s:old-tax-{$k}")); }
$post1($gPlain('2026-11-10', 's:old-plain-gets-tax'));
$post1($gTax('2026-12-10', 's:new-tax-into'));
$sTaxUpd     = $manual()->create($mTax('2026-11-12', 'S old tax update'));
$sTaxEarlier = $manual()->create($mTax('2026-11-12', 'S old tax earlier'));
$sTaxDel     = $manual()->create($mTax('2026-11-12', 'S old tax delete'));
$sPlainGet   = $manual()->create($mPlain('2026-11-13', 'S old plain gets tax'));
$setStart('2026-12-01');

$before = $books();
$caught = thrown(fn() => $post1($gTax('2026-11-20', 's:new-tax')));
$refusedStart = [
    'post tax'                  => $outcome(fn() => $post1($gTax('2026-11-20', 's:new-tax'))),
    'post tax, the day before'  => $outcome(fn() => $post1($gTax('2026-11-30', 's:new-tax-30'))),
    'manual create tax'         => $outcome(fn() => $manual()->create($mTax('2026-11-20', 'S neu vor dem Tag'))),
    'reverse tax, dated before' => $outcome(fn() => $reverse('s:old-tax-reverse-before', '2026-11-25', 'S Storno vor dem Tag')),
    'amend tax moved earlier'   => $outcome(fn() => $amend('s:old-tax-earlier', $gTax('2026-11-05', 's:old-tax-earlier'))),
    'amend gets a tax line'     => $outcome(fn() => $amend('s:old-plain-gets-tax', $gTax('2026-11-10', 's:old-plain-gets-tax'))),
    'amend tax moved before'    => $outcome(fn() => $amend('s:new-tax-into', $gTax('2026-11-15', 's:new-tax-into'))),
    'update tax moved earlier'  => $outcome(fn() => $manual()->update(...[...$at($sTaxEarlier), $mTax('2026-11-02', 'S old tax earlier')])),
    'update gets a tax line'    => $outcome(fn() => $manual()->update(...[...$at($sPlainGet), $mTax('2026-11-13', 'S old plain gets tax')])),
];
check('S1 BEFORE «Abrechnung ab» (1.12.2026) × TAX LINE — refused `before-vat-start`: post (also on 30.11.), a manual entry, a reversal DATED before the day, amend / manual update that MOVE a tax entry earlier, GIVE an entry a tax line there, or move one from after the day to before it; nothing written — entries, lines, change rows and the number range as before',
    $only($refusedStart, 'before-vat-start') === '' && $books() === $before, $only($refusedStart, 'before-vat-start') . ' ' . json_encode([$before, $books()]));
check('S2 the refusal: PostingRefusedException `before-vat-start` (the same key on EntryNotEditableException), no VAT period on it, the message names the day and the date',
    $caught instanceof PostingRefusedException && $caught->reason === 'before-vat-start' && PostingRefusedException::BEFORE_VAT_START === EntryNotEditableException::BEFORE_VAT_START
    && $caught->vatPeriod === null && str_contains($caught->getMessage(), '01.12.2026') && str_contains($caught->getMessage(), '20.11.2026'),
    $caught === null ? 'nothing thrown' : get_class($caught) . ': ' . $caught->getMessage());

$acceptedStart = [
    'post plain, before'                  => $outcome(fn() => $post1($gPlain('2026-11-20', 's:new-plain'))),
    'post tax, on the day'                => $outcome(fn() => $post1($gTax('2026-12-01', 's:new-tax-on'))),
    'amend tax (text), same date'         => $outcome(fn() => $amend('s:old-tax-amend', $gTax('2026-11-10', 's:old-tax-amend', 'S Verkauf geändert'))),
    'amend tax moved later, still before' => $outcome(fn() => $amend('s:old-tax-later', $gTax('2026-11-20', 's:old-tax-later'))),
    'amend loses its tax line, earlier'   => $outcome(fn() => $amend('s:old-tax-loses', $gPlain('2026-11-08', 's:old-tax-loses'))),
    'retract tax'                         => $outcome(fn() => $retract('s:old-tax-retract')),
    'reverse tax, dated after the day'    => $outcome(fn() => $reverse('s:old-tax-reverse-after', '2026-12-02', 'S Storno ab dem Tag')),
    'update tax (text)'                   => $outcome(fn() => $manual()->update(...[...$at($sTaxUpd), $mTax('2026-11-12', 'S geändert')])),
    'delete tax'                          => $outcome(fn() => $manual()->delete(...$at($sTaxDel))),
];
check('S3 what stays possible: a posting WITHOUT a tax line before the day, a tax line ON the day; an EXISTING tax entry before the day corrected on its date or later (amend, manual update — plan §5.1, open board question 9 answered as recommended), losing its tax line, retracted, deleted, reversed with a date on / after the day',
    $only($acceptedStart, 'ok') === '' && $db->fetchOne("SELECT entry_date FROM journal_entry WHERE idempotency_key = 's:old-tax-later'") === '2026-11-20'
    && $db->fetchOne("SELECT text FROM journal_entry WHERE idempotency_key = 's:old-tax-amend'") === 'S Verkauf geändert'
    && $count('journal_entry', "idempotency_key = 's:old-tax-retract'") === 0 && $count('journal_entry', "text = 'S old tax delete'") === 0
    && $count('journal_entry', "idempotency_key = 'reversal:2026/" . $refOf('s:old-tax-reverse-after')->number . "' AND entry_date = '2026-12-02'") === 1,
    $only($acceptedStart, 'ok'));

$db->executeStatement('UPDATE mandator SET liable_to_vat = 0');
$notLiable        = $outcome(fn() => $post1($gTax('2026-11-21', 's:not-liable')));
$notLiableRefusal = (new LedgerService($wire(), 'harness'))->periodRefusal(day('2026-11-21'), true);
$db->executeStatement('UPDATE mandator SET liable_to_vat = 1');
$setStart(null);
$notSet           = $outcome(fn() => $post1($gTax('2026-11-22', 's:not-set')));
$notSetRefusal    = (new LedgerService($wire(), 'harness'))->periodRefusal(day('2026-11-22'), true);
$notSetText       = (new LedgerService($wire(), 'harness'))->periodRefusalText('before-vat-start', day('2026-11-22'));
$setStart('2026-12-01');
check('S4 no rule while the mandator is NOT liable, and none while «Abrechnung ab» is NOT set (a liable mandator that settles its VAT outside z77 — ADR-042 addendum point 6, nothing blocks): a tax line dated before 1.12. posts, periodRefusal() answers null; the sentence without a day names the field',
    $notLiable === 'ok' && $notLiableRefusal === null && $notSet === 'ok' && $notSetRefusal === null
    && $notSetText === 'Vor «Abrechnung ab» wird in z77 keine MWST abgerechnet — eine Buchung mit MWST-Code geht dort nicht',
    json_encode([$notLiable, $notLiableRefusal, $notSet, $notSetRefusal, $notSetText], JSON_UNESCAPED_UNICODE));

$ledger = new LedgerService($wire(), 'harness');
check('S5 the public answer, lock-free — LedgerService::periodRefusal(): `before-vat-start` for a tax line on 30.11.2026, null without one and on 1.12.; `no-fiscal-year` is asked first (2025); before the day wins over a settled period (Q3, settled AND before 1.12.); periodRefusalText() is the one German sentence naming the day',
    $ledger->periodRefusal(day('2026-11-30'), true) === 'before-vat-start' && $ledger->periodRefusal(day('2026-11-30'), false) === null
    && $ledger->periodRefusal(day('2026-12-01'), true) === null && $ledger->periodRefusal(day('2025-12-31'), true) === PostingRefusedException::NO_FISCAL_YEAR
    && $ledger->periodRefusal(day('2026-08-01'), true) === 'before-vat-start'
    && $ledger->periodRefusalText('before-vat-start', day('2026-11-30')) === 'Vor dem 01.12.2026 wird in z77 keine MWST abgerechnet — eine Buchung mit MWST-Code geht dort nicht',
    json_encode([$ledger->periodRefusal(day('2026-11-30'), true), $ledger->periodRefusal(day('2026-08-01'), true), $ledger->periodRefusalText('before-vat-start', day('2026-11-30'))], JSON_UNESCAPED_UNICODE));

$sRulesSrc  = (string) file_get_contents($root . '/packages/module-financial/src/Services/PostingRules.php');
$sLedgerSrc = (string) file_get_contents($root . '/packages/module-financial/src/Services/LedgerService.php');
check('S6 source guard: the «Abrechnung ab» rule is decided in ONE place — PostingRules::vatStartCrossed(), asked by assertVatAccepts() and assertVatAllowsChange() only; the day is read through PostingRules::vatStart() (CurrentMandator::vatSettings()); LedgerService asks vatStart() and reads no mandator field for it',
    substr_count($sRulesSrc, '$this->vatStartCrossed(') === 2 && substr_count($sRulesSrc, 'private function vatStartCrossed(') === 1
    && str_contains($sRulesSrc, '(new CurrentMandator($this->em))->vatSettings()')
    && substr_count($sLedgerSrc, '$this->rules->vatStart()') === 2 && !str_contains($sLedgerSrc, 'getVatSettlementFrom'));
$setStart('2026-01-01');

$checkpoint->save('P', $db, $base, []);
section_P:
// ─────────────────────────────────────────────────────────────────────────
echo "P. «MWST buchen» — the real writer: the close check, preconditions and refusals, a nil return, a posted return, «Eingereicht am»\n";

// The books of P, G and R: fiscal years 2027 (1.1.–31.8.2027, a short year) and 2028 (1.9.2027–31.8.2028), so that Q3 2027
// spans two years (G14); «Abrechnung ab» 1.1.2027 — everything of the sections A–S lies before it. Q1 2027 has no coded line
// (a nil return); Q2 2027 a sale and a purchase; G14's first half (July, August — still in 2027) is posted now, before 2027 closes.
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2027', day('2027-01-01'), day('2027-08-31')));
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2028', day('2027-09-01'), day('2028-08-31')));
$db->executeStatement("UPDATE mandator SET vat_settlement_from = '2027-01-01', liable_to_vat = 1");
$post1($sale('2027-05-10', 'p:q2-sale', '1000.00', '81.00'));
$post1($buy('2027-06-05', 'p:q2-buy', '4200', '1170', 'VM', '200.00', '16.20'));
$post1($sale('2027-07-15', 'g:e1', '2000.06', '162.00'));
$post1($buy('2027-08-20', 'g:e2', '4200', '1170', 'VM', '500.00', '40.50'));
$q127 = $qOf(2027, 1);
$q227 = $qOf(2027, 2);
$today = '2027-07-10';

$closeQ = $closeFindings('2027', '2027-01-01', '2027-08-31');
check('P1 the year-close check (UnsettledVatPeriodsCheck, registered under period-close): one BLOCKING finding per VAT period that ENDS in 2027 and is not settled — Q1 and Q2, named by their label; NOT Q3 2027 (it ends on 30.9., in 2028); 2026 lies before «Abrechnung ab»: none',
    $closeQ === [
        'B Die MWST-Periode 1. Quartal 2027 ist nicht abgerechnet — zuerst die MWST-Abrechnung buchen (Finanzen › MWST-Abrechnungen). @vat-return:2027-01-01',
        'B Die MWST-Periode 2. Quartal 2027 ist nicht abgerechnet — zuerst die MWST-Abrechnung buchen (Finanzen › MWST-Abrechnungen). @vat-return:2027-04-01',
    ] && $closeFindings('2026', '2026-01-01', '2026-12-31') === []
    && in_array(UnsettledVatPeriodsCheck::class, (require $root . '/packages/module-financial/src/App/Config/financialConfig.inc.php')['openWorkChecks']['period-close'], true),
    json_encode($closeQ, JSON_UNESCAPED_UNICODE));

$before = $booksOf('2027');
$p2     = $poster()->plan($q227, day($today));
$early  = $poster()->plan($q127, day('2027-03-20'));
$refusedP = [
    'not-next'        => $why(fn() => $poster()->post($q227, $p2->figuresFingerprint, $p2->warningsFingerprint, null, day($today))),
    'not-elapsed'     => $why(fn() => $poster()->post($q127, $early->figuresFingerprint, null, null, day('2027-03-20'))),
    'not-a-period'    => $why(fn() => $poster()->plan(new VatPeriod(day('2027-01-15'), day('2027-04-14'), VatRhythm::Quarter), day($today))),
    'filed in future' => $why(fn() => $postReturn($q127, '2027-04-10', '2027-04-20')),
    'filed in period' => $why(fn() => $postReturn($q127, '2027-04-10', '2027-03-31')),
];
$filedText = (thrown(fn() => $postReturn($q127, '2027-04-10', '2027-03-31')))?->getMessage();
check('P2 refused before anything is written: Q2 while Q1 is open `not-next` — the plan names it as its FIRST blocking finding —, Q1 before its end `not-elapsed`, a span that is no period of the calendar `not-a-period`, «Eingereicht am» after today or not after the period\'s end `filed-on-invalid`; the sentences; no entry, no number, no row, no voucher',
    $refusedP === ['not-next' => 'not-next', 'not-elapsed' => 'not-elapsed', 'not-a-period' => 'not-a-period', 'filed in future' => 'filed-on-invalid', 'filed in period' => 'filed-on-invalid']
    && $p2->blocking()[0]->code === VatReturnRefusal::NOT_NEXT && $p2->blocking()[0]->message === 'Zuerst die MWST-Periode 1. Quartal 2027 abrechnen — die Abrechnungen folgen einander ohne Lücke.'
    && $early->blocking()[0]->message === 'Die MWST-Periode 1. Quartal 2027 endet erst am 31.03.2027 — gebucht wird nach ihrem Ende.'
    && $filedText === '«Eingereicht am» 31.03.2027 liegt nicht nach dem Ende der MWST-Periode 1. Quartal 2027 (31.03.2027).'
    && $booksOf('2027') === $before && $vouchers() === [], json_encode([$refusedP, $filedText, $booksOf('2027'), $before], JSON_UNESCAPED_UNICODE));

$nilPlan = $poster()->plan($q127, day('2027-04-10'));
$nil     = $postReturn($q127, '2027-04-10', '2027-04-08');
$nilRow  = $db->fetchAssociative("SELECT id, revision, state, live, payable, posted_tax, rounding_difference, settlement_entry_id, filed_on, posted_by, voucher_path, voucher_sha256, voucher_name FROM vat_return WHERE kind = 'ordinary' AND period_from = '2027-01-01'");
$nilPdf  = (string) @file_get_contents($base . '/' . $nilRow['voucher_path']);
$nilLog  = $db->fetchAssociative('SELECT action, actor, detail FROM vat_return_log WHERE vat_return_id = ?', [$nilRow['id']]);
check('P3 a NIL return (Q1 2027, no coded line — owner decision 4a): the plan has no line, no entry, no finding; posted, it writes the row (revision 1, posted, live, payable / posted tax / rounding 0.00, NO settlement entry, «Eingereicht am» 8.4.2027, posted by harness), one figure per field — all zero —, the protocol `post` (filed date and voucher in its detail) and the voucher: a PDF under data/archive/financial/vat-return/2027/, its SHA-256 on the row, offered as mwst-abrechnung-2027-01-01-2027-03-31-r1.pdf; no journal entry, no number drawn',
    $nilPlan->isNil() && $nilPlan->settlement === null && $nilPlan->findings === [] && $nilPlan->revision === 1 && $nil->isNil()
    && $nilRow['revision'] === 1 && $nilRow['state'] === 'posted' && $nilRow['live'] === 1 && $nilRow['payable'] === '0.00' && $nilRow['posted_tax'] === '0.00' && $nilRow['rounding_difference'] === '0.00'
    && $nilRow['settlement_entry_id'] === null && $nilRow['filed_on'] === '2027-04-08' && $nilRow['posted_by'] === 'harness'
    && str_starts_with($nilRow['voucher_path'], 'data/archive/financial/vat-return/2027/mwst-abrechnung-2027-01-01-2027-03-31-r1-') && str_starts_with($nilPdf, '%PDF')
    && hash('sha256', $nilPdf) === $nilRow['voucher_sha256'] && $nilRow['voucher_name'] === 'mwst-abrechnung-2027-01-01-2027-03-31-r1.pdf'
    && $count('vat_return_figure', "vat_return_id = {$nilRow['id']}") === count($nilPlan->preview->fields) && $count('vat_return_figure', "vat_return_id = {$nilRow['id']} AND value_exact <> 0") === 0
    && $nilLog['action'] === 'post' && $nilLog['actor'] === 'harness' && json_decode((string) $nilLog['detail'], true) === ['filed_on' => '2027-04-08', 'voucher' => $nilRow['voucher_path']]
    && $booksOf('2027')[0] === $before[0] && $booksOf('2027')[3] === $before[3] && count($vouchers()) === 1,
    json_encode([$nilRow, $nilLog], JSON_UNESCAPED_UNICODE));

$svc     = new VatReturnService($wire(), null, new OpenWorkChecks([]));
$again   = thrown(fn() => $postReturn($q127, '2027-04-10'));
check('P4 Q1 is settled — read from the row: settled, «Nächste Abrechnung» Q2; Q1 again is refused `already-settled` («Die MWST-Periode 1. Quartal 2027 ist bereits abgerechnet (gebucht am … von harness).»)',
    $svc->isSettled($q127) && $span($svc->nextReturn(day($today))) === '2027-04-01..2027-06-30'
    && $again instanceof VatReturnRefusedException && $again->refusal->reason === VatReturnRefusal::ALREADY_SETTLED
    && $again->getMessage() === 'Die MWST-Periode 1. Quartal 2027 ist bereits abgerechnet (gebucht am ' . (new \DateTimeImmutable())->format('d.m.Y') . ' von harness).',
    $again === null ? 'nothing thrown' : $again->getMessage());

// Q2: a revenue line without a code (a warning); the warnings and the figures confirmed or not.
$post1(PostingRequest::generated(day('2027-06-12'), 'Dienstleistung ohne Code', 'harness', 'p:q2-uncoded', 'p:q2-uncoded', [PostingLine::debit('1020', chf('50.00')), PostingLine::credit('3400', chf('50.00'))]));
$w      = $poster()->plan($q227, day($today));
$before = $booksOf('2027');
$unconfirmed = thrown(fn() => $poster()->post($q227, $w->figuresFingerprint, null, null, day($today)));
$changedW    = $why(fn() => $poster()->post($q227, $w->figuresFingerprint, hash('sha256', 'other warnings'), null, day($today)));
check('P5 warnings: the plan of Q2 has ONE warning (the uncoded revenue line on 3400) and its fingerprint; post() without confirming refuses `warnings-unconfirmed` (the warnings ride on the exception), with another fingerprint `warnings-changed`; nothing written',
    count($w->warnings()) === 1 && $w->warnings()[0]->code === 'uncoded-revenue' && $w->blocking() === [] && $w->warningsFingerprint === VatReturnPostingService::warningsFingerprint($w->findings)
    && $unconfirmed instanceof VatReturnRefusedException && $unconfirmed->refusal->reason === VatReturnRefusal::WARNINGS_UNCONFIRMED && count($unconfirmed->findings) === 1
    && $unconfirmed->getMessage() === 'Die Warnungen der MWST-Abrechnung 2. Quartal 2027 sind nicht bestätigt — lesen und bestätigen, dann buchen.'
    && $changedW === VatReturnRefusal::WARNINGS_CHANGED && $booksOf('2027') === $before, json_encode([$w->findings, $changedW], JSON_UNESCAPED_UNICODE));

$post1($sale('2027-06-20', 'p:q2-late', '100.00', '8.10'));   // lands between the dialog and the click
$before   = $booksOf('2027');
$changedF = thrown(fn() => $poster()->post($q227, $w->figuresFingerprint, $w->warningsFingerprint, null, day($today)));
check('P6 a sale lands between the dialog and the click: post() with the figures fingerprint the poster saw refuses `figures-changed` — the figures filed in the portal are not posted unseen; nothing written',
    $changedF instanceof VatReturnRefusedException && $changedF->refusal->reason === VatReturnRefusal::FIGURES_CHANGED
    && $changedF->getMessage() === 'Die Zahlen der MWST-Abrechnung 2. Quartal 2027 haben sich seit der Anzeige geändert (eine Buchung kam dazu) — die Abrechnung neu ansehen; was im ePortal schon eingereicht ist, vergleichen.'
    && $booksOf('2027') === $before, $changedF === null ? 'nothing thrown' : $changedF->getMessage());

$db->executeStatement("UPDATE mandator SET account_vat_settlement = ''");
$missingPlan = $poster()->plan($q227, day($today));
$missing     = thrown(fn() => $postReturn($q227, $today));
$db->executeStatement("UPDATE mandator SET account_vat_settlement = '2201'");
$db->executeStatement("UPDATE fiscal_period SET state = 'closed' WHERE fiscal_year_id = ?", [$fyId('2027')]);
$closedPlan = $poster()->plan($q227, day($today));
$closed     = thrown(fn() => $postReturn($q227, $today));
$db->executeStatement("UPDATE fiscal_period SET state = 'open' WHERE fiscal_year_id = ?", [$fyId('2027')]);
check('P7 `account-missing` — the mandator names no settlement account: the plan has the blocking finding and no entry, post() refuses with the sentence; `year-closed` — the year of the period\'s last day closed: refused with the sentence; nothing written',
    $missingPlan->settlement === null && $missingPlan->blocking()[0]->code === VatReturnRefusal::ACCOUNT_MISSING
    && $missing instanceof VatReturnRefusedException && $missing->refusal->reason === VatReturnRefusal::ACCOUNT_MISSING
    && $missing->getMessage() === 'Für die Abrechnungsbuchung ist kein buchbares Konto «Abrechnungskonto MWST» hinterlegt — im Mandanten hinterlegen (Stammdaten › Firma › Mandant, Reiter «Konten»).'
    && $closedPlan->blocking()[0]->code === VatReturnRefusal::YEAR_CLOSED
    && $closed instanceof VatReturnRefusedException && $closed->refusal->reason === VatReturnRefusal::YEAR_CLOSED
    && $closed->getMessage() === 'Das Geschäftsjahr 2027 ist abgeschlossen — wieder öffnen (Admin), die MWST abrechnen, wieder abschliessen.'
    && $booksOf('2027') === $before, json_encode([$missing?->getMessage(), $closed?->getMessage()], JSON_UNESCAPED_UNICODE));

// Blocking findings: an uncoded line on 2200 (the VAT paid «the old way»), open work of the registry, one account for owed AND input tax.
$post1(PostingRequest::generated(day('2027-06-25'), 'MWST bezahlt von Hand', 'harness', 'p:q2-2200', 'p:q2-2200', [PostingLine::debit('2200', chf('10.00')), PostingLine::credit('1020', chf('10.00'))]));
$before    = $booksOf('2027');
$remainder = thrown(fn() => $postReturn($q227, $today));
$inLedger(static fn(LedgerService $l) => $l->retract('p:q2-2200', 'harness'));
$openWork  = thrown(fn() => $postReturn($q227, $today, null, new OpenWorkChecks(['vat-return' => [HarnessOpenInvoiceCheck::class]])));
$db->executeStatement("UPDATE mandator SET account_vat_input_material = '2200'");
$shared    = $poster()->plan($q227, day($today));
$db->executeStatement("UPDATE mandator SET account_vat_input_material = '1170'");
$codesOf   = static fn(?\Throwable $e): array => $e instanceof VatReturnRefusedException ? array_map(static fn($f): string => $f->code . ' ' . $f->reference, $e->findings) : [];
check('P8 `blocked` — a blocking finding refuses, the findings ride on the exception: an uncoded line on 2200 in Q2 (`vat-account-remainder` — never absorbed by the rounding account); an open-work finding of the registry (debtor\'s «Faktura-Journal», here a stand-in — the slice-3 rule «issued, not yet posted» is that registry\'s); ONE account named for owed AND input tax (vatAccounts() merges the slot, the input tax counts with the wrong sign — a permanent `vat-account-remainder`, the plan has no entry); nothing written',
    $remainder instanceof VatReturnRefusedException && $remainder->refusal->reason === VatReturnRefusal::BLOCKED && $codesOf($remainder) === ['vat-account-remainder account:2200']
    && $remainder->getMessage() === '1 offener Punkt sperrt das Buchen der MWST-Abrechnung 2. Quartal 2027 — siehe «Offene Punkte».'
    && $openWork instanceof VatReturnRefusedException && $openWork->refusal->reason === VatReturnRefusal::BLOCKED && $codesOf($openWork) === ['open-work invoice-journal']
    && in_array('vat-account-remainder account:2200', array_map(static fn($f): string => $f->code . ' ' . $f->reference, $shared->blocking()), true) && $shared->settlement === null
    && $booksOf('2027')[4] === $before[4] && $count('journal_entry', "idempotency_key = 'p:q2-2200'") === 0,
    json_encode([$codesOf($remainder), $codesOf($openWork), array_map(static fn($f): string => $f->code . ' ' . $f->reference, $shared->blocking())], JSON_UNESCAPED_UNICODE));
$debtorConfig = (string) file_get_contents($root . '/packages/module-debtor/src/App/Config/debtorConfig.inc.php');
check('P9 the open-work check of slice 3 («documents issued but not yet posted with a date in the period») IS the registry: debtor registers JournalForVatReturnCheck under `vat-return`, creditor and receipt their unposted-journal checks; VatReturnService::findings() asks the scope and a finding blocks «MWST buchen» (P8)',
    str_contains($debtorConfig, "'vat-return'    => [\\Z77\\Module\\Debtor\\Close\\JournalForVatReturnCheck::class]")
    && str_contains((string) file_get_contents($root . '/packages/module-creditor/src/App/Config/creditorConfig.inc.php'), "'vat-return'    => [UnpostedCreditorInvoicesCheck::class]")
    && str_contains((string) file_get_contents($root . '/packages/module-receipt/src/App/Config/receiptConfig.inc.php'), "'vat-return'    => [UnpostedReceiptsCheck::class]"));

$q2Plan = $poster()->plan($q227, day($today));
$before = $booksOf('2027');
$q2     = $poster()->post($q227, $q2Plan->figuresFingerprint, $q2Plan->warningsFingerprint, null, day($today));
$q2Key  = 'vat-return:2027-04-01:2027-06-30:r1';
$q2Row  = $db->fetchAssociative("SELECT r.*, e.idempotency_key, e.entry_date, e.text, e.source_type, e.source_ref, e.kind AS entry_kind, e.created_by, e.number, y.code FROM vat_return r JOIN journal_entry e ON e.id = r.settlement_entry_id JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE r.id = ?", [$q2->getId()]);
$fig    = static fn(int $id, string $field): array => $db->fetchAssociative('SELECT value_exact, value_rounded, base, line_count FROM vat_return_figure WHERE vat_return_id = ? AND field = ?', [$id, $field]) ?: [];
$q2Log  = json_decode((string) $db->fetchOne('SELECT detail FROM vat_return_log WHERE vat_return_id = ?', [$q2->getId()]), true);
$mapping = array_map(static fn(array $a): string => "{$a['code']}/{$a['category']}/{$a['rate']}>{$a['field']}", json_decode((string) $q2Row['mapping_snapshot'], true));
check('P10 Q2 2027 posted: the settlement entry ' . $q2Row['code'] . '/' . $q2Row['number'] . ' — dated 30.6.2027 (the period\'s last day), «MWST-Abrechnung 2. Quartal 2027», generated, source vat-return, reference 2027-04-01/2027-06-30/r1, key …:r1, posted by harness —, lines Dr 2200 89.10 (the CODED tax: 81.00 + 8.10), Cr 1170 16.20, Cr 2201 72.90 (the payable amount as declared), no rounding line, no tax code on any line',
    $linesOf($q2Key) === ['2200 D 89.10', '1170 C 16.20', '2201 C 72.90'] && $q2Row['idempotency_key'] === $q2Key && $q2Row['entry_date'] === '2027-06-30'
    && $q2Row['text'] === 'MWST-Abrechnung 2. Quartal 2027' && $q2Row['source_type'] === 'vat-return' && $q2Row['source_ref'] === '2027-04-01/2027-06-30/r1'
    && $q2Row['entry_kind'] === 'generated' && $q2Row['created_by'] === 'harness' && $q2Row['code'] === '2027'
    && $count('journal_line', 'entry_id = ' . (int) $q2Row['settlement_entry_id'] . ' AND tax_code IS NOT NULL') === 0
    && $booksOf('2027')[3] === $before[3] + 1, json_encode([$linesOf($q2Key), $q2Row['entry_date'] ?? null]));
check('P11 the return row of Q2: revision 1, posted, live; payable 72.90, posted tax 72.90, rounding 0.00; the method / consideration / rhythm / country / form edition snapshotted; the RESOLVED mapping (UN standard 8.1 % → 303, VM input-material → 400) and the SHA-256 of the rule file ch.json; the figures fingerprint and the warnings fingerprint as confirmed, the confirmed warning sentence; one figure per field — 303 exact 89.100000 on the base 1100.00 (2 lines), 400 16.20, 500 72.90 —; the protocol `post` naming the entry, the warning and the voucher; the voucher archived',
    (int) $q2Row['revision'] === 1 && $q2Row['state'] === 'posted' && (int) $q2Row['live'] === 1 && $q2Row['payable'] === '72.90' && $q2Row['posted_tax'] === '72.90' && $q2Row['rounding_difference'] === '0.00'
    && $q2Row['method'] === 'effective' && $q2Row['consideration'] === 'agreed' && $q2Row['rhythm'] === 'quarter' && $q2Row['country'] === 'CH' && $q2Row['form_edition'] === $q2Plan->preview->edition
    && in_array('UN/standard/810>303', $mapping, true) && in_array('VM/input-material/810>400', $mapping, true)
    && $q2Row['mapping_hash'] === hash_file('sha256', $root . '/packages/module-vat/res/country-packs/ch.json')
    && $q2Row['figures_fingerprint'] === $q2Plan->figuresFingerprint && $q2Row['warnings_fingerprint'] === $q2Plan->warningsFingerprint
    && json_decode((string) $q2Row['confirmed_warnings'], true) === [$q2Plan->warnings()[0]->message]
    && $count('vat_return_figure', 'vat_return_id = ' . $q2->getId()) === count($q2Plan->preview->fields)
    && $fig($q2->getId(), '303') === ['value_exact' => '89.100000', 'value_rounded' => '89.10', 'base' => '1100.00', 'line_count' => 2]
    && $fig($q2->getId(), '400')['value_rounded'] === '16.20' && $fig($q2->getId(), '500')['value_rounded'] === '72.90'
    && $q2Log === ['entry' => '2027/' . $q2Row['number'], 'warnings' => [$q2Plan->warnings()[0]->message], 'voucher' => $q2Row['voucher_path']]
    && hash_file('sha256', $base . '/' . $q2Row['voucher_path']) === $q2Row['voucher_sha256'] && count($vouchers()) === 2,
    json_encode([$fig($q2->getId(), '303'), $q2Log, $mapping], JSON_UNESCAPED_UNICODE));

$movedQ2 = $db->fetchAllKeyValue("SELECT a.number, SUM(l.debit) - SUM(l.credit) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id WHERE a.number IN ('2200', '1170') AND e.entry_date BETWEEN '2027-04-01' AND '2027-06-30' GROUP BY a.number ORDER BY a.number");
$frozen  = $why(fn() => $post1($gTax('2027-05-20', 'p:q2-after')));
$plainIn = $why(fn() => $post1($gPlain('2027-05-20', 'p:q2-after-plain')));
check('P12 after the settlement the VAT accounts of Q2 stand at zero (2200, 1170 — the settlement entry cleared exactly the coded tax); the freeze is LIVE with a real return: a tax posting into Q2 is refused `vat-period-settled`, one without a tax line passes; the service shows Q2 «gebucht», «Nächste Abrechnung» Q3 once it has ended',
    array_map('floatval', $movedQ2) === ['1170' => 0.0, '2200' => 0.0] && $frozen === 'vat-period-settled' && $plainIn === 'ok'
    && (new VatReturnService($wire(), null, new OpenWorkChecks([])))->isSettled($q227) && $span((new VatReturnService($wire(), null, new OpenWorkChecks([])))->nextReturn(day('2027-10-10'))) === '2027-07-01..2027-09-30',
    json_encode([$movedQ2, $frozen, $plainIn]));

$version  = static fn(int $id): int => (int) $db->fetchOne('SELECT version FROM vat_return WHERE id = ?', [$id]);
$v1       = $version($q2->getId());
$poster()->setFiled($q2->getId(), $v1, day('2027-07-12'), day('2027-07-20'));
$stale    = $why(fn() => $poster()->setFiled($q2->getId(), $v1, day('2027-07-13'), day('2027-07-20')));
$v2       = $version($q2->getId());
$badDay   = $why(fn() => $poster()->setFiled($q2->getId(), $v2, day('2027-06-30'), day('2027-07-20')));
$same     = $poster()->setFiled($q2->getId(), $v2, day('2027-07-12'), day('2027-07-20'));
$poster()->setFiled($q2->getId(), $v2, null, day('2027-07-20'));
$liftedId = (int) $db->fetchOne("SELECT id FROM vat_return WHERE period_from = '2026-01-01' AND revision = 1");
$notPosted = $why(fn() => $poster()->setFiled($liftedId, $version($liftedId), day('2026-04-12'), day('2026-05-01')));
$notFound  = $why(fn() => $poster()->setFiled(999999, 1, null, day('2027-07-20')));
check('P13 «Eingereicht am» after posting (setFiled): set to 12.7.2027 — the version moves —; a stale version `stale`, a day not after the period `filed-on-invalid`, the same day again writes nothing, cleared; a lifted return `not-posted`, an unknown one `not-found`; the protocol post → filed {12.7.} → unfiled {was 12.7.}',
    $v2 === $v1 + 1 && $stale === VatReturnRefusal::STALE && $badDay === VatReturnRefusal::FILED_ON_INVALID && $same->getVersion() === $v2
    && $notPosted === VatReturnRefusal::NOT_POSTED && $notFound === VatReturnRefusal::NOT_FOUND
    && $db->fetchOne('SELECT filed_on FROM vat_return WHERE id = ?', [$q2->getId()]) === null
    && array_map(static fn(array $r): string => $r['action'] . ' ' . (string) $r['detail'], $db->fetchAllAssociative('SELECT action, detail FROM vat_return_log WHERE vat_return_id = ? ORDER BY id', [$q2->getId()]))
        === ['post ' . json_encode($q2Log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'filed {"filed_on":"2027-07-12"}', 'unfiled {"was":"2027-07-12"}'],
    json_encode([$v1, $v2, $stale, $badDay, $notPosted, $notFound]));

$checkpoint->save('G', $db, $base, []);
section_G:
// ─────────────────────────────────────────────────────────────────────────
echo "G. Golden case G14 — a VAT period spanning two fiscal years (2027 / 2028), and the year close\n";

// From P: Q1 (nil) and Q2 2027 settled; Q3 2027 holds July / August in 2027 (g:e1 sale 2000.06 / 162.00, g:e2 purchase 500.00 / 40.50).
$q327 = $qOf(2027, 3);
$closeYear('2026');
$closeYear('2027');
$closedYears = $db->fetchAllKeyValue("SELECT y.code, SUM(p.state = 'closed') = COUNT(*) FROM fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id GROUP BY y.code ORDER BY y.code");
check('G1 the year close WAITS only for the periods ending in the year: 2026 (before «Abrechnung ab») and 2027 (Q1, Q2 settled) close — 2027 holds July and August of Q3 2027, which ends on 30.9.2027 in 2028 and is not asked; 2028 stays open',
    array_map('intval', $closedYears) === ['2026' => 1, '2027' => 1, '2028' => 0], json_encode($closedYears));

$hintSet = (new UnsettledVatPeriodsCheck($wire()))->hint();
$db->executeStatement('UPDATE mandator SET vat_settlement_from = NULL');
$hintUnset  = (new UnsettledVatPeriodsCheck($wire()))->hint();
$noFindings = $closeFindings('2028', '2027-09-01', '2028-08-31');
$db->executeStatement('UPDATE mandator SET liable_to_vat = 0');
$hintNotLiable = (new UnsettledVatPeriodsCheck($wire()))->hint();
$db->executeStatement("UPDATE mandator SET liable_to_vat = 1, vat_settlement_from = '2027-01-01'");
check('G2 without «Abrechnung ab» NOTHING blocks the close (ADR-042 addendum point 6, owner 2026-11-24): no finding for 2028 although nothing of it is settled; the hint a screen may show (not a registry warning — that would need a tick at every close); no hint while the day is set or the mandator is not liable',
    $noFindings === [] && $hintSet === null && $hintNotLiable === null
    && $hintUnset === 'Der Mandant ist mehrwertsteuerpflichtig, aber «Abrechnung ab» ist nicht festgelegt — z77 prüft beim Abschluss nicht, ob die MWST des Jahres abgerechnet ist.',
    json_encode([$noFindings, $hintSet, $hintUnset, $hintNotLiable], JSON_UNESCAPED_UNICODE));

// September 2027 — in 2028: a sale (1000.05 / 81.00) and a purchase of other expenses (200.00 / 16.20, VI → 1171).
$post1($sale('2027-09-10', 'g:e3', '1000.05', '81.00'));
$post1($buy('2027-09-25', 'g:e4', '6500', '1171', 'VI', '200.00', '16.20'));
$g       = $poster()->plan($q327, day('2027-10-10'));
$rounded = static fn(string $field): string => $g->preview->field($field)->rounded->toDecimal();
$gLines  = array_map(static fn(array $l): string => $l['role'] . ' ' . $l['account'] . ' ' . ($l['debit']->isZero() ? 'C ' . $l['credit']->toDecimal() : 'D ' . $l['debit']->toDecimal()), $g->lines);
check('G3 G14, the worked figures: 200 = 3000.11 (2000.06 + 1000.05 — both fiscal years), 303 base 3000.11 tax EXACT 243.008910 (243.01), 399 243.01, 400 40.50, 405 16.20, 479 56.70, 500 = 243.008910 − 56.70 rounded ONCE = 186.31; posted 243.00 − 56.70 = 186.30 → rounding −0.01; no finding (a Rappen over four coded documents is within half a Rappen each)',
    $rounded('200') === '3000.11' && $g->preview->field('303')->base?->toDecimal() === '3000.11' && $g->preview->field('303')->value->toDecimal() === '243.008910'
    && $rounded('303') === '243.01' && $rounded('399') === '243.01' && $rounded('400') === '40.50' && $rounded('405') === '16.20' && $rounded('479') === '56.70'
    && $rounded('500') === '186.31' && $rounded('510') === '0.00' && $g->payable()->toDecimal() === '186.31' && $g->preview->form->postedTax->toDecimal() === '186.30'
    && $g->roundingDifference()->toDecimal() === '-0.01' && $g->findings === [] && $g->warningsFingerprint === null,
    json_encode([$rounded('200'), $g->preview->field('303')->value->toDecimal(), $rounded('500'), $g->preview->form->postedTax->toDecimal(), array_map(static fn($f) => $f->code . ' ' . $f->message, $g->findings)], JSON_UNESCAPED_UNICODE));
check('G4 G14, the settlement entry as planned: Dr 2200 243.00 (the coded output tax of BOTH years), Cr 1170 40.50, Cr 1171 16.20, Cr 2201 186.31 (declared), Dr 6945 0.01 (declared > posted) — balanced',
    $gLines === ['owed 2200 D 243.00', 'input 1170 C 40.50', 'input 1171 C 16.20', 'settlement 2201 C 186.31', 'rounding 6945 D 0.01'], json_encode($gLines));

$g14    = $poster()->post($q327, $g->figuresFingerprint, $g->warningsFingerprint, null, day('2027-10-10'));
$gKey   = 'vat-return:2027-07-01:2027-09-30:r1';
$gEntry = $db->fetchAssociative('SELECT e.entry_date, e.number, y.code FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE e.idempotency_key = ?', [$gKey]);
$byYear = $db->fetchAllKeyValue("SELECT CONCAT(y.code, ' ', a.number), SUM(l.credit) - SUM(l.debit) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN fiscal_year y ON y.id = e.fiscal_year_id JOIN account a ON a.id = l.account_id
    WHERE a.number IN ('2200', '1170', '1171', '2201', '6945') AND e.entry_date BETWEEN '2027-07-01' AND '2027-09-30' GROUP BY y.code, a.number ORDER BY y.code, a.number");
$both   = $db->fetchAllKeyValue("SELECT a.number, SUM(l.credit) - SUM(l.debit) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN account a ON a.id = l.account_id
    WHERE a.number IN ('2200', '1170', '1171') AND e.entry_date BETWEEN '2027-07-01' AND '2027-09-30' GROUP BY a.number ORDER BY a.number");
check('G5 G14 posted although 2027 is CLOSED: the entry goes into the fiscal year of the period\'s last day — 2028, dated 30.9.2027 —, lines as planned; the row payable 186.31 / posted 186.30 / rounding −0.01; per fiscal year the VAT accounts keep what 2027 posted (2200 162.00 Cr, 1170 40.50 Dr — what its close left and the year opening carries) and 2028 clears both years; over the whole period 2200, 1170, 1171 stand at zero',
    $gEntry === ['entry_date' => '2027-09-30', 'number' => $gEntry['number'], 'code' => '2028'] && $linesOf($gKey) === ['2200 D 243.00', '1170 C 40.50', '1171 C 16.20', '2201 C 186.31', '6945 D 0.01']
    && $g14->getPayable()->toDecimal() === '186.31' && $g14->getPostedTax()->toDecimal() === '186.30' && $g14->getRoundingDifference()->toDecimal() === '-0.01'
    && $byYear === ['2027 1170' => '-40.50', '2027 2200' => '162.00', '2028 1170' => '40.50', '2028 1171' => '0.00', '2028 2200' => '-162.00', '2028 2201' => '186.31', '2028 6945' => '-0.01']
    && array_map('floatval', $both) === ['1170' => 0.0, '1171' => 0.0, '2200' => 0.0],
    json_encode([$gEntry, $linesOf($gKey), $byYear, $both]));

$voucherText = VatReturnPdf::voucher($g->preview, $g->reconciliation, $g->lines, '2028/' . $gEntry['number'], [], 1, 'harness', day('2027-10-10 09:00'), day('2027-10-08'), '3. Quartal 2027', null)->withoutCompression()->output();
$closeQ28    = $closeFindings('2028', '2027-09-01', '2028-08-31');
$sum28       = (new VatReturnService($wire(), null, new OpenWorkChecks([])))->periods($wire()->getRepository(FiscalYear::class)->find($fyId('2028')), day('2028-09-10'));
check('G6 the voucher carries the settlement entry (number, lines), «Herkunft», «Abstimmung», «Eingereicht am», «gebucht am … von harness», «Keine Einreichung»; the close check of 2028 now names Q4 2027, Q1 and Q2 2028 — not Q3 2027; the list of 2028 shows Q3 2027 «gebucht» with the DECLARED 186.31',
    str_contains($voucherText, 'Abrechnungsbuchung 2028/' . $gEntry['number']) && str_contains($voucherText, '186.31') && str_contains($voucherText, 'Herkunft') && str_contains($voucherText, 'Abstimmung')
    && str_contains($voucherText, 'Eingereicht am') && str_contains($voucherText, '08.10.2027') && str_contains($voucherText, 'von harness') && str_contains($voucherText, 'Keine Einreichung')
    && array_map(static fn(string $f): string => substr($f, strrpos($f, '@') + 1), $closeQ28) === ['vat-return:2027-10-01', 'vat-return:2028-01-01', 'vat-return:2028-04-01']
    && $sum28[0]->period->from->format('Y-m-d') === '2027-07-01' && $sum28[0]->stateLabel === 'gebucht' && $sum28[0]->payable?->toDecimal() === '186.31',
    json_encode([$closeQ28, array_map(static fn($s) => $s->period->from->format('Y-m-d') . ' ' . $s->stateLabel, $sum28)], JSON_UNESCAPED_UNICODE));

$checkpoint->save('R', $db, $base, []);
section_R:
// ─────────────────────────────────────────────────────────────────────────
echo "R. Two processes — a return in flight against postings, edits, a second return, a type change and the year close (plan §2.3)\n";

// From G: 2028 open (1.9.2027–31.8.2028), Q3 2027 settled; Q4 2027, Q1 and Q2 2028 open. The entries of the probes:
$q427 = $qOf(2027, 4);
$q128 = $qOf(2028, 1);
$q228 = $qOf(2028, 2);
$post1($sale('2027-11-05', 'r:q4-sale', '300.00', '24.30'));
$post1($sale('2028-01-20', 'r:q1-sale', '400.00', '32.40'));
$post1($gTax('2028-02-05', 'r:q1-amend'));
$post1($gTax('2028-02-05', 'r:q1-retract'));
$mUpd = $manual()->create($mTax('2028-02-06', 'R q1 update'));
$mDel = $manual()->create($mTax('2028-02-06', 'R q1 delete'));
$post1($sale('2028-05-10', 'r:q2-sale', '500.00', '40.50'));

// VR-R1: a tax posting in flight (the worker holds it uncommitted), then «MWST buchen» of its period.
$r1Plan = $poster()->plan($q427, day('2028-01-10'));
$r1     = $inFlight(['holdpost', '2027-11-15', 'r:held'], fn() => $poster()->post($q427, $r1Plan->figuresFingerprint, $r1Plan->warningsFingerprint, null, day('2028-01-10')));
$r1Next = $poster()->plan($q427, day('2028-01-10'));
$r1Ret  = $postReturn($q427, '2028-01-10');
check('VR-R1 a tax posting in flight, then «MWST buchen» of its period with the fingerprint shown before: the return WAITED (worker: ' . $r1[0] . ') and then saw the entry — refused `figures-changed` (' . $r1[1] . '), no row; shown again the plan includes it (303: base 300.00 → 800.00) and posts',
    $r1[0] === 'ok waited' && $r1[1] === VatReturnRefusal::FIGURES_CHANGED && $count('journal_entry', "idempotency_key = 'r:held'") === 1
    && $r1Plan->preview->field('303')->base?->toDecimal() === '300.00' && $r1Next->preview->field('303')->base?->toDecimal() === '800.00'
    && $r1Ret->getPayable()->toDecimal() === '64.80' && $count('vat_return', "kind = 'ordinary' AND period_from = '2027-10-01'") === 1, json_encode($r1));

$before = $booksOf('2028');
$r2 = $inFlight(['postreturn', '2028-01-01', '2028-03-31', '2028-04-10'], fn() => $post1($gTax('2028-02-10', 'r:r2-tax')));
$after = $booksOf('2028');
$undoReturn('2028-01-01');
check('VR-R2 a return in flight (the REAL service, held after its locks), then a tax posting into its period: the posting WAITED (worker: ' . $r2[0] . ') and was refused `vat-period-settled` (' . $r2[1] . ') — no deadlock; the journal range moved by the settlement entry only (the posting\'s number went back)',
    str_starts_with($r2[0], 'ok waited #') && $r2[1] === 'vat-period-settled' && $count('journal_entry', "idempotency_key = 'r:r2-tax'") === 0
    && $after[3] === $before[3] + 1 && $after[4] === $before[4] + 1, json_encode([$r2, $before, $after]));

$r3 = $inFlight(['postreturn', '2028-01-01', '2028-03-31', '2028-04-10'], fn() => $post1($gPlain('2028-02-11', 'r:r3-plain')));
$undoReturn('2028-01-01');
check('VR-R3 a return in flight, then a posting WITHOUT a tax line into its period: it waited (worker: ' . $r3[0] . ') and was accepted',
    str_starts_with($r3[0], 'ok waited #') && $r3[1] === 'ok' && $count('journal_entry', "idempotency_key = 'r:r3-plain'") === 1, json_encode($r3));

$r4 = [];
foreach ([
    'amend'         => fn() => $amend('r:q1-amend', $gTax('2028-02-05', 'r:q1-amend', 'R geändert')),
    'retract'       => fn() => $retract('r:q1-retract'),
    'manual update' => fn() => $manual()->update(...[...$atIn($mUpd), $mTax('2028-02-06', 'R q1 update — geändert')]),
    'manual delete' => fn() => $manual()->delete(...$atIn($mDel)),
] as $what => $write) {
    $r4[$what] = $inFlight(['postreturn', '2028-01-01', '2028-03-31', '2028-04-10'], $write);
    $undoReturn('2028-01-01');
}
check('VR-R4 a return in flight, then amend, retract, manual update and manual delete of a tax entry in its period: each WAITED (they meet the return at the period row) and was refused `vat-period-settled`; the entries unchanged',
    array_map(static fn(array $r): string => (str_starts_with($r[0], 'ok waited #') ? 'waited' : $r[0]) . ' ' . $r[1], $r4)
        === ['amend' => 'waited vat-period-settled', 'retract' => 'waited vat-period-settled', 'manual update' => 'waited vat-period-settled', 'manual delete' => 'waited vat-period-settled']
    && $count('journal_entry', "idempotency_key IN ('r:q1-amend', 'r:q1-retract')") === 2 && $count('journal_entry', "text IN ('R q1 update', 'R q1 delete')") === 2,
    json_encode($r4, JSON_UNESCAPED_UNICODE));

$r6Plan = $poster()->plan($q128, day('2028-04-10'));
$r6 = $inFlight(['postreturn', '2028-01-01', '2028-03-31', '2028-04-10'], fn() => $poster()->post($q128, $r6Plan->figuresFingerprint, $r6Plan->warningsFingerprint, null, day('2028-04-10')));
check('VR-R6 «MWST buchen» twice for one period: the second WAITED at the writer row (worker: ' . $r6[0] . ') and was refused `already-settled` (' . $r6[1] . ') — one return, one settlement entry',
    str_starts_with($r6[0], 'ok waited #') && $r6[1] === VatReturnRefusal::ALREADY_SETTLED
    && $count('vat_return', "kind = 'ordinary' AND period_from = '2028-01-01'") === 1 && $count('journal_entry', "idempotency_key LIKE 'vat-return:2028-01-01:%'") === 1, json_encode($r6));

$r8 = $inFlight(['postreturn', '2028-04-01', '2028-06-30', '2028-07-10'], function () use ($wire): void {
    $em = $wire();
    (new AccountService($em))->update($em->getRepository(Account::class)->findOneBy(['number' => '1171']), ['type' => 'expense']);
});
$r8Type = (string) $db->fetchOne("SELECT type FROM account WHERE number = '1171'");
if ($r8Type !== 'asset') {
    $em = $wire();
    (new AccountService($em))->update($em->getRepository(Account::class)->findOneBy(['number' => '1171']), ['type' => 'asset']);
}
$undoReturn('2028-04-01');
check('VR-R8 a return in flight, then a type change of a VAT account (1171 — the return holds it shared): it WAITED (worker: ' . $r8[0] . ') and ran after the commit (' . $r8[1] . ') — no deadlock, no lock timeout',
    str_starts_with($r8[0], 'ok waited #') && !str_contains($r8[1], 'eadlock') && !str_contains($r8[1], 'Lock wait'), json_encode($r8));

$em5  = $wire();
$fp28 = FiscalYearCloseService::warningsFingerprint((new FiscalYearCloseService($em5, 'harness'))->closeCheck($em5->getRepository(FiscalYear::class)->find($fyId('2028'))));
$r5a  = $inFlight(['postreturn', '2028-04-01', '2028-06-30', '2028-07-10'], fn() => (new FiscalYearCloseService($wire(), 'harness'))->close($fyId('2028'), $fp28));
$closed28 = (int) $db->fetchOne("SELECT SUM(state = 'closed') = COUNT(*) FROM fiscal_period WHERE fiscal_year_id = ?", [$fyId('2028')]);
check('VR-R5a a return in flight (Q2 2028, the last unsettled period of 2028), then the close of 2028: the close WAITED at the fiscal-year rows (worker: ' . $r5a[0] . ') and then read the committed return — every period ending in 2028 settled, it closed (' . $r5a[1] . ')',
    str_starts_with($r5a[0], 'ok waited #') && $r5a[1] === 'ok' && $closed28 === 1, json_encode([$r5a, $closed28]));

(new FiscalYearCloseService($wire(), 'harness'))->reopen($fyId('2028'), 'Harness VR-R5b');
$undoReturn('2028-04-01');
$r5bPlan = $poster()->plan($q228, day('2028-07-10'));
$r5b = $inFlight(['holdclose', '2028'], fn() => $poster()->post($q228, $r5bPlan->figuresFingerprint, $r5bPlan->warningsFingerprint, null, day('2028-07-10')));
check('VR-R5b the other order — a year close in flight (2028, held in its close check), then «MWST buchen» of Q2 2028: the return WAITED at the fiscal-year rows; the close found Q2 unsettled and was refused `blocked` (worker: ' . $r5b[0] . '), the return then posted (' . $r5b[1] . ') — no deadlock; 2028 open, Q2 settled',
    $r5b[0] === 'refused blocked waited' && $r5b[1] === 'ok' && $count('vat_return', "kind = 'ordinary' AND live = 1 AND period_from = '2028-04-01'") === 1
    && (int) $db->fetchOne("SELECT SUM(state = 'closed') FROM fiscal_period WHERE fiscal_year_id = ?", [$fyId('2028')]) === 0, json_encode($r5b));

echo "\n{$pass} passed, {$fail} failed" . ($fromSection !== null ? " (from section {$fromSection}, its checkpoint)" : '') . "\n";
exit($fail === 0 ? 0 : 1);
