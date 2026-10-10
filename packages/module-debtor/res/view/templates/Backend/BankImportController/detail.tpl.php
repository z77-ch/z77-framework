<?php
/**
 * One CAMT.054 message (P4 part 2): its transactions with state, the
 * matched invoice (a link to its detail, a window) and what is still open
 * on it, the remainder of an overpayment, the note — and the actions:
 * «Zuordnen» (a document number) and «Ignorieren» / «Zurücknehmen» per
 * transaction that is not booked, «Verbuchen» for every matched one — at
 * the TOP, with the notice «n zugeordnet · noch nicht verbucht» and as the
 * green confirm (`.be-btn--confirm`, it writes; 2026-10-08).
 *
 * The row and the bar are partials (`transactionRow`, `unbookedBar`): a row
 * action is a fetch POST that answers with exactly these two in place
 * (ADR-047 addendum 2026-10-10) — one row changes, nothing reloads. Without
 * the script the forms are page POSTs as before; every POST carries
 * `csrf_token` (`#[Csrf]`). No JavaScript of its own (Rule 7).
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
$rowContext = [
    'messageId'   => (int) $message->getId(),
    'open'        => $open,
    'states'      => $states,
    'invoiceBase' => $invoiceBase,
    'fmt'         => $fmt,
    'csrfToken'   => $csrfToken ?? '',
    'actionBase'  => $actionBase,
];
?>
<div class="be-list">
    <?= $this->partial('Backend/BankImportController/unbookedBar', [
        'matched'    => (int) $counts['matched'],
        'messageId'  => (int) $message->getId(),
        'csrfToken'  => $csrfToken ?? '',
        'actionBase' => $actionBase,
    ], 'Z77\\Module\\Debtor') ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Meldung <?= e($message->getMessageId()) ?>
                <small class="be-list__cell--muted">· <?= e($message->getFileName()) ?> · vom <?= e($message->getCreatedOn()->format('d.m.Y')) ?> · Konto <?= e($message->getPaymentTargetCode()) ?> (<?= e($message->getIban()) ?>) · importiert <?= e($message->getImportedAt()->format('d.m.Y H:i')) ?> von <?= e($message->getImportedBy()) ?></small>
            </h2>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/list">Zurück zur Liste</a>
        </div>

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
            <?php foreach ($message->getTransactions() as $t): ?>
            <?= $this->partial('Backend/BankImportController/transactionRow', ['t' => $t] + $rowContext, 'Z77\\Module\\Debtor') ?>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Verbuchen legt je zugeordnete Transaktion eine Zahlung auf der Rechnung an (Valuta, Konto des Zahlungsziels). Mehr als offen: der Rest bleibt als Überzahlung stehen. Bereits beglichen: nichts gebucht, die Transaktion wird wieder offen.</p>
    </div>
</div>
