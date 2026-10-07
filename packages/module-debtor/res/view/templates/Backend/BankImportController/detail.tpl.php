<?php
/**
 * One CAMT.054 message (P4 part 2): its transactions with state, the
 * matched invoice (a link to its detail) and what is still open on it,
 * the remainder of an overpayment, the note — and the actions: «Zuordnen»
 * (a document number) and «Ignorieren» / «Zurücknehmen» per transaction
 * that is not booked, «Verbuchen» for every matched one. Page forms, no
 * JavaScript (Rule 7); every POST carries `csrf_token` (`#[Csrf]`).
 *
 * @var \Z77\Module\Debtor\Entities\BankMessage $message
 * @var array<int, \Z77\Shared\Money\Money> $open  invoice id → open amount now
 * @var array<string, int> $counts
 * @var array<string, string> $states
 * @var string $invoiceBase
 * @var callable $fmt
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/bank-import';
$detail     = $actionBase . '/detail?id=' . (int) $message->getId();
$badge      = static fn(string $state): string => match ($state) {
    'booked'    => 'badge--success',
    'matched'   => 'badge--info',
    'unmatched' => 'badge--warning',
    default     => 'badge--muted',
};
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Meldung <?= e($message->getMessageId()) ?>
                <small class="be-list__cell--muted">· <?= e($message->getFileName()) ?> · vom <?= e($message->getCreatedOn()->format('d.m.Y')) ?> · Konto <?= e($message->getPaymentTargetCode()) ?> (<?= e($message->getIban()) ?>) · importiert <?= e($message->getImportedAt()->format('d.m.Y H:i')) ?> von <?= e($message->getImportedBy()) ?></small>
            </h2>
        </div>

        <nav class="be-list__toggles" aria-label="Aktionen">
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/list">Zurück zur Liste</a>
            <?php if ($counts['matched'] > 0): ?>
            <form method="post" action="<?= e($actionBase) ?>/book?id=<?= (int) $message->getId() ?>" style="display: inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <button type="submit" class="be-btn be-btn--primary be-btn--sm">Verbuchen (<?= (int) $counts['matched'] ?> zugeordnete)</button>
            </form>
            <?php endif; ?>
        </nav>

        <div class="be-list__table" style="--be-list-cols: 2.5rem 6rem 7rem minmax(12rem, 2fr) minmax(10rem, 2fr) 7rem minmax(12rem, 3fr)">
            <div class="be-list__head">
                <span class="be-list__col">#</span>
                <span class="be-list__col">Valuta</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
                <span class="be-list__col">Zahler · Referenz</span>
                <span class="be-list__col">Rechnung · offen</span>
                <span class="be-list__col">Status</span>
                <span class="be-list__col">Hinweis · Aktion</span>
            </div>
            <?php foreach ($message->getTransactions() as $t): $state = $t->state()->value; $invoice = $t->getInvoice(); ?>
            <div class="be-list__item" data-bank-transaction="<?= (int) $t->getId() ?>" data-state="<?= e($state) ?>">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--num"><?= (int) $t->getPosition() ?></span>
                    <span class="be-list__cell"><?= e($t->getValueDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($t->getCurrency()) ?> <?= e($fmt($t->getAmount())) ?></span>
                    <span class="be-list__cell be-list__cell--wrap">
                        <?= e($t->getDebtorName() !== '' ? $t->getDebtorName() : '–') ?><?= $t->getDebtorCity() !== '' ? ', ' . e($t->getDebtorCity()) : '' ?>
                        <br><small class="be-list__cell--muted"><?= e($t->getReferenceType() !== '' ? $t->getReferenceType() : '–') ?><?= $t->getReference() !== '' ? ' ' . e($t->getReference()) : '' ?><?= $t->getRemittance() !== '' ? ' · ' . e($t->getRemittance()) : '' ?></small>
                    </span>
                    <span class="be-list__cell be-list__cell--wrap">
                        <?php if ($invoice !== null): ?>
                        <a href="<?= e($invoiceBase) ?>/detail?id=<?= (int) $invoice->getId() ?>"><?= e($invoice->documentName()) ?></a>
                        <small class="be-list__cell--muted">· <?= e($invoice->getAddress()->getName()) ?> · offen <?= e($fmt($open[$invoice->getId()] ?? null)) ?></small>
                        <?php if ($t->getRemainder()->isPositive()): ?>
                        <br><span class="badge badge--warning">Überzahlung <?= e($fmt($t->getRemainder())) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="be-list__cell--muted">–</span>
                        <?php endif; ?>
                    </span>
                    <span class="be-list__cell"><span class="badge <?= $badge($state) ?>"><?= e($states[$state] ?? $state) ?></span></span>
                    <span class="be-list__cell be-list__cell--wrap">
                        <?php if ($t->getNote() !== null): ?><small><?= e($t->getNote()) ?></small><br><?php endif; ?>
                        <?php if ($t->getPayment() !== null): ?><small class="be-list__cell--muted">Zahlung #<?= (int) $t->getPayment()->getId() ?></small><?php endif; ?>
                        <?php if ($state !== 'booked'): ?>
                        <form method="post" action="<?= e($actionBase) ?>/assign?id=<?= (int) $message->getId() ?>" style="display: inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                            <input type="hidden" name="transaction" value="<?= (int) $t->getId() ?>">
                            <input class="be-input be-input--sm" type="text" name="number" inputmode="numeric" placeholder="Rechnung Nr." aria-label="Rechnungsnummer" style="width: 7rem">
                            <button type="submit" class="be-btn be-btn--ghost be-btn--sm">Zuordnen</button>
                        </form>
                        <form method="post" action="<?= e($actionBase) ?>/ignore?id=<?= (int) $message->getId() ?>" style="display: inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                            <input type="hidden" name="transaction" value="<?= (int) $t->getId() ?>">
                            <input type="hidden" name="value" value="<?= $state === 'ignored' ? '0' : '1' ?>">
                            <button type="submit" class="be-btn be-btn--ghost be-btn--sm"><?= $state === 'ignored' ? 'Zurücknehmen' : 'Ignorieren' ?></button>
                        </form>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Verbuchen legt je zugeordnete Transaktion eine Zahlung auf der Rechnung an (Valuta, Konto des Zahlungsziels). Mehr als offen: der Rest bleibt als Überzahlung stehen. Bereits beglichen: nichts gebucht, die Transaktion wird wieder offen.</p>
    </div>
</div>
