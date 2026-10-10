<?php
/**
 * One transaction of a CAMT.054 message — the row of `detail`, and the HTML a
 * row action answers with (`FetchResponse::replaceRow('bank-transaction', id)`,
 * ADR-047 addendum 2026-10-10): the same markup on the page and in place.
 *
 * «Zuordnen» and «Ignorieren» / «Zurücknehmen» are fetch POSTs
 * (`data-fetch-post`) that replace THIS row and the unbooked bar — nothing
 * else on the page changes; core.js wires the forms of the row it puts in, so
 * the next click on the same row is in place again. Each form keeps
 * `method="post"` + `action`: without the script it is the page POST (flash
 * + redirect to the detail) it always was.
 *
 * @var \Z77\Module\Debtor\Entities\BankTransaction $t
 * @var int $messageId
 * @var array<int, \Z77\Shared\Money\Money> $open  invoice id → open amount now
 * @var array<string, string> $states
 * @var string $invoiceBase
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
$state   = $t->state()->value;
$invoice = $t->getInvoice();
$badge   = match ($state) {
    'booked'    => 'badge--success',
    'matched'   => 'badge--info',
    'unmatched' => 'badge--warning',
    default     => 'badge--muted',
};
$assign = $actionBase . '/assign?id=' . (int) $messageId;
$ignore = $actionBase . '/ignore?id=' . (int) $messageId;
?>
<div class="be-list__item" data-entity="bank-transaction:<?= (int) $t->getId() ?>" data-bank-transaction="<?= (int) $t->getId() ?>" data-state="<?= e($state) ?>">
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
            <a href="<?= e($invoiceBase) ?>/detail?id=<?= (int) $invoice->getId() ?>" data-window-open="<?= e($invoiceBase) ?>/detail?id=<?= (int) $invoice->getId() ?>"><?= e($invoice->documentName()) ?></a>
            <small class="be-list__cell--muted">· <?= e($invoice->getAddress()->getName()) ?> · offen <?= e($fmt($open[$invoice->getId()] ?? null)) ?></small>
            <?php if ($t->getRemainder()->isPositive()): ?>
            <br><span class="badge badge--warning">Überzahlung <?= e($fmt($t->getRemainder())) ?></span>
            <?php endif; ?>
            <?php else: ?>
            <span class="be-list__cell--muted">–</span>
            <?php endif; ?>
        </span>
        <span class="be-list__cell"><span class="badge <?= $badge ?>"><?= e($states[$state] ?? $state) ?></span></span>
        <span class="be-list__cell be-list__cell--wrap">
            <?php if ($t->getNote() !== null): ?><small><?= e($t->getNote()) ?></small><br><?php endif; ?>
            <?php if ($t->getPayment() !== null): ?><small class="be-list__cell--muted">Zahlung #<?= (int) $t->getPayment()->getId() ?></small><?php endif; ?>
            <?php if ($state !== 'booked'): ?>
            <form method="post" action="<?= e($assign) ?>" data-fetch-post="<?= e($assign) ?>" style="display: inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <input type="hidden" name="transaction" value="<?= (int) $t->getId() ?>">
                <input class="be-input be-input--sm" type="text" name="number" inputmode="numeric" placeholder="Rechnung Nr." aria-label="Rechnungsnummer" style="width: 7rem">
                <button type="submit" class="be-btn be-btn--ghost be-btn--sm">Zuordnen</button>
            </form>
            <form method="post" action="<?= e($ignore) ?>" data-fetch-post="<?= e($ignore) ?>" style="display: inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <input type="hidden" name="transaction" value="<?= (int) $t->getId() ?>">
                <input type="hidden" name="value" value="<?= $state === 'ignored' ? '0' : '1' ?>">
                <button type="submit" class="be-btn be-btn--ghost be-btn--sm"><?= $state === 'ignored' ? 'Zurücknehmen' : 'Ignorieren' ?></button>
            </form>
            <?php endif; ?>
        </span>
    </div>
</div>
