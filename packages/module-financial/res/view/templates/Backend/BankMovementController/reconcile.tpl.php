<?php
/**
 * «Abgleich …» of ONE bank-driven bank account (ADR-061 decisions 22–26, stage C4) — a WINDOW
 * (the toolbar tool of «Bankkonto» opens it; without the script a page). Figures and labels only;
 * what they mean stands in the help (`reconcile.help`, backend-screen.md §5):
 *
 *   - the ANCHOR: «Bank X · Fibu Y · Differenz Z» at the opening of a day, with its state — and
 *     the answer «Bestätigen» in the action row while there is something to confirm (one POST,
 *     `as_of` + the difference the office saw; `BankReconciliation::confirm()` refuses when the
 *     figures moved); on an account that is not «gemischt geführt» never — the refusal's sentence
 *     (`$notMixed`, `BankRefusedException::anchorNotMixed()`) stands under a mismatch instead (owner 2026-11-19);
 *   - the CHECK per period of the chosen fiscal year: Bank geschäftlich · Fibu · Differenz ·
 *     Zu prüfen · Buchungen ohne Bewegung — a period that does not agree is marked;
 *   - the postings without a movement of the year, each a link into the journal;
 *   - the transfer clearing account's balance and the control line «Vorsteuer ohne Beleg».
 *
 * Nothing here decides anything: every figure is `BankReconciliation`'s. No JavaScript of its own.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount $account
 * @var array{state: string, asOf: \DateTimeImmutable, bank: ?\Z77\Shared\Money\Money, ledger: \Z77\Shared\Money\Money, difference: ?\Z77\Shared\Money\Money, expected: ?\Z77\Shared\Money\Money, anchor: ?\Z77\Module\Financial\Entities\BankAnchor, reason: string}|null $anchor
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var list<array{label: string, check: \Z77\Module\Financial\Bank\BankCheck}> $periods
 * @var \Z77\Module\Financial\Bank\BankCheck|null $total  the check over the whole year (its postings without a movement)
 * @var array{account: \Z77\Module\Financial\Entities\Account, balance: \Z77\Shared\Money\Money}|null $transfer
 * @var array{count: int, tax: \Z77\Shared\Money\Money}|null $tax
 * @var string $error
 * @var string $notMixed  '' on a mixed account, else the sentence why nothing is confirmed
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 * @var string $journalBase
 */
use Z77\Module\Financial\Bank\BankReconciliation;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::MOVEMENT_BASE;
$window     = !empty($window);
$title      = 'Abgleich · ' . $account->getLabel();
$query      = static fn(array $more = []): string => http_build_query(['account' => $account->getCode()] + $more);
$action     = $actionBase . '/reconcile?' . $query($year !== null ? ['year' => $year->getCode()] : []);
$notMixed   = (string) ($notMixed ?? '');
$open       = $anchor !== null && in_array($anchor['state'], [BankReconciliation::ANCHOR_OPEN, BankReconciliation::ANCHOR_MISMATCH], true) && $anchor['difference'] !== null;
// Not «gemischt geführt» (owner 2026-11-19): nothing is confirmed — the refusal's sentence stands where the button would (once, not beside the same error).
$confirm    = $open && $notMixed === '';
$refused    = $open && $notMixed !== '' && ($error ?? '') !== $notMixed;
$states     = [
    BankReconciliation::ANCHOR_CONFIRMED => 'bestätigt',
    BankReconciliation::ANCHOR_COMPUTED  => 'berechnet, stimmt',
    BankReconciliation::ANCHOR_OPEN      => 'nicht bestätigt',
    BankReconciliation::ANCHOR_MISMATCH  => 'weicht ab',
    BankReconciliation::ANCHOR_UNREAD    => 'nicht eingelesen',
];
$amount     = static fn(?Money $money): string => $money === null ? '–' : AmountFormat::of($money);
$winAttr    = $window ? ' data-window="bank-reconcile" data-window-entity="bankAccount:' . (int) $account->getId() . ':reconcile" data-window-title="' . e($title) . '" data-window-width="56rem"' : '';
?>
<div class="be-list"<?= $winAttr ?> data-bank-reconcile="<?= e($account->getCode()) ?>">
    <form method="post" action="<?= e($action) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if ($confirm): ?>
        <input type="hidden" name="as_of" value="<?= e($anchor['asOf']->format('Y-m-d')) ?>">
        <input type="hidden" name="difference" value="<?= e($anchor['difference']->toDecimal()) ?>">
        <?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $confirm ? 'Differenz bestätigen' : '',
            'cancelHref' => $window ? '' : $actionBase . '/list?' . $query(),
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <?php if (($error ?? '') !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($anchor !== null): ?>
        <div class="be-list__table" style="--be-list-cols: 9rem minmax(12rem, 1fr)" data-reconcile-anchor="<?= e($anchor['state']) ?>">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Abgleich ab</span><span class="be-list__cell"><?= e((string) $account->getReconcileFrom()?->format('d.m.Y')) ?> · Konto <?= e($account->getLedgerAccount()) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Per <?= e($anchor['asOf']->format('d.m.Y')) ?></span><span class="be-list__cell"<?= $confirm ? ' data-reconcile-confirm' : '' ?>>
                <?php if ($anchor['bank'] === null): ?>
                <?= e($anchor['reason']) ?>
                <?php else: ?>
                Bank <?= e($amount($anchor['bank'])) ?> · Fibu <?= e($amount($anchor['ledger'])) ?> · <strong>Differenz <?= e($amount($anchor['difference'])) ?></strong>
                <small class="be-list__cell--muted">· <?= e($states[$anchor['state']] ?? '') ?><?php if ($anchor['state'] === BankReconciliation::ANCHOR_MISMATCH): ?> — erwartet <?= e($amount($anchor['expected'])) ?><?php endif; ?><?php if ($anchor['state'] === BankReconciliation::ANCHOR_CONFIRMED && $anchor['anchor'] !== null): ?> am <?= e($anchor['anchor']->getConfirmedAt()->format('d.m.Y')) ?> von <?= e($anchor['anchor']->getConfirmedBy()) ?><?php endif; ?></small>
                <?php endif; ?>
            </span></div></div>
            <?php if ($refused): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell"></span><span class="be-list__cell" data-reconcile-not-mixed><?= e($notMixed) ?></span></div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (count($years) > 1): ?>
        <nav class="be-viewtabs" aria-label="Geschäftsjahr">
            <?php foreach ($years as $other): ?>
            <?php $href = $actionBase . '/reconcile?' . $query(['year' => $other->getCode()]); $current = $year !== null && $other->getCode() === $year->getCode(); ?>
            <a class="be-viewtabs__tab<?= $current ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $window ? ' data-window-open="' . e($href) . '" data-origin="' . e($origin ?? 'page') . '"' : '' ?><?= $current ? ' aria-current="page"' : '' ?>><?= e($other->getCode()) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php if ($year === null): ?>
        <p class="be-list__empty">Kein Geschäftsjahr eröffnet.</p>
        <?php elseif ($periods === []): ?>
        <p class="be-list__empty">Im Geschäftsjahr <?= e($year->getCode()) ?> gibt es nichts abzugleichen.</p>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: 7rem repeat(3, minmax(7rem, 1fr)) 6rem 8rem 5rem" data-reconcile-periods>
            <div class="be-list__head">
                <span class="be-list__col">Periode</span>
                <span class="be-list__col be-list__col--num">Bank geschäftlich</span>
                <span class="be-list__col be-list__col--num">Fibu</span>
                <span class="be-list__col be-list__col--num">Differenz</span>
                <span class="be-list__col be-list__col--num">Zu prüfen</span>
                <span class="be-list__col be-list__col--num">Ohne Bewegung</span>
                <span class="be-list__col">Stand</span>
            </div>
            <?php foreach ($periods as $row): ?>
            <?php $check = $row['check']; $ok = $check->passes(); $read = $check->isReadFrom() && $check->isReadTo(); ?>
            <div class="be-list__item" data-reconcile-period="<?= e($check->to->format('Y-m')) ?>" data-reconcile-ok="<?= $ok ? '1' : '0' ?>"><div class="be-list__row">
                <span class="be-list__cell"><?= e($row['label']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(AmountFormat::of($check->business)) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(AmountFormat::of($check->ledger)) ?></span>
                <span class="be-list__cell be-list__cell--num"><?php if ($check->difference()->isZero()): ?><span class="be-list__cell--muted">0.00</span><?php else: ?><strong><?= e(AmountFormat::of($check->difference())) ?></strong><?php endif; ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $check->review > 0 ? '<strong>' . $check->review . '</strong>' : '<span class="be-list__cell--muted">0</span>' ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $check->entryCount > 0 ? '<strong>' . $check->entryCount . '</strong>' : '<span class="be-list__cell--muted">0</span>' ?></span>
                <span class="be-list__cell"><?php if ($ok): ?><span class="badge badge--success">ok</span><?php elseif (!$read): ?><span class="badge badge--warning">nicht eingelesen</span><?php else: ?><span class="badge badge--warning">offen</span><?php endif; ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($total !== null && $total->entryCount > 0): ?>
        <div class="be-list__section-header"><h3 class="be-list__section-title">Buchungen ohne Bewegung</h3><span class="be-list__section-badge"><?= $total->entryCount ?></span></div>
        <div class="be-list__table" style="--be-list-cols: 6rem 6rem minmax(10rem, 1fr) 7rem 10rem" data-reconcile-entries>
            <div class="be-list__head">
                <span class="be-list__col">Nr.</span>
                <span class="be-list__col">Datum</span>
                <span class="be-list__col">Text</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
                <span class="be-list__col">Hinweis</span>
            </div>
            <?php foreach ($total->entries as $entry): ?>
            <?php $href = $journalBase . '/detail?' . http_build_query(['ref' => $entry['ref']]); ?>
            <div class="be-list__item" data-reconcile-entry="<?= e($entry['ref']) ?>"><div class="be-list__row">
                <a class="be-list__cell be-list__cell--mono" href="<?= e($href) ?>" data-window-open="<?= e($href) ?>"><?= e($entry['ref']) ?></a>
                <span class="be-list__cell be-list__cell--mono"><?= e((new \DateTimeImmutable($entry['date']))->format('d.m.Y')) ?></span>
                <span class="be-list__cell"><?= e($entry['text']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(AmountFormat::of(Money::fromDecimal($entry['amount'], $account->getCurrency()))) ?></span>
                <span class="be-list__cell be-list__cell--muted"><?= e($entry['note']) ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php if ($total->entryCount > count($total->entries)): ?>
        <p class="be-list__empty">… und <?= $total->entryCount - count($total->entries) ?> weitere.</p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($total !== null): ?>
        <div class="be-list__table" style="--be-list-cols: 14rem minmax(12rem, 1fr)" data-reconcile-facts>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Eingelesen bis</span><span class="be-list__cell"><?= e($total->coveredTo !== null ? $total->coveredTo->format('d.m.Y') : '–') ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Privat (nicht gebucht)</span><span class="be-list__cell"><?= e(AmountFormat::of($total->private)) ?></span></div></div>
            <?php if ($total->unbooked['count'] > 0): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Geschäftlich ohne Buchung</span><span class="be-list__cell" data-reconcile-unbooked><strong><?= (int) $total->unbooked['count'] ?></strong> · <?= e(AmountFormat::of($total->unbooked['sum'])) ?></span></div></div>
            <?php endif; ?>
            <?php if ($transfer !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Übertrag eigene Bankkonten</span><span class="be-list__cell" data-reconcile-transfer><?= e($transfer['account']->label()) ?> · Saldo <?php if ($transfer['balance']->isZero()): ?>0.00<?php else: ?><strong><?= e(AmountFormat::of($transfer['balance'])) ?></strong><?php endif; ?></span></div></div>
            <?php endif; ?>
            <?php if ($tax !== null && $tax['count'] > 0): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Vorsteuer ohne Beleg</span><span class="be-list__cell" data-reconcile-tax><?= $tax['count'] === 1 ? '1 Buchung' : (int) $tax['count'] . ' Buchungen' ?> · <?= e($tax['tax']->currency) ?> <?= e(AmountFormat::of($tax['tax'])) ?> Vorsteuer</span></div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
