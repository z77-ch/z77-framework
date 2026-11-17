<?php
/**
 * «Zahlungen suchen …» (toolbar of the Belegjournal) and «Zahlung suchen …» (one receipt's window)
 * — ADR-061 decision 11, stage C5 — a WINDOW (without the script a page). Per receipt the bank
 * movements that fit, as ONE radio group `take[{receipt id}]` = `{version}:{movement id}` (the
 * version the window showed) plus «nicht zuordnen» (empty):
 *
 *   - a proposal → its movement is TICKED IN ADVANCE; the office confirms several at once with
 *     «Übernehmen»;
 *   - candidates → nothing ticked («nicht zuordnen» is), the office picks — never a guess. A
 *     candidate in a foreign currency shows the original amount beside the CHF the bank debited.
 *
 * Nothing is decided here: the POST hands each ticked pair to `ReceiptBankMatchService::take()`,
 * which judges basis and pair again on the server. Labels only — the explanation is the help
 * section `bank` (backend-screen.md §5). Bank text is foreign input: every value goes through
 * `e()`. Twin of module-creditor's `confirmBank` (the shared partial is pending, receipt.md).
 *
 * @var list<array{receipt: \Z77\Module\Receipt\Entities\Receipt, match: \Z77\Module\Receipt\Services\ReceiptBankMatch}> $rows
 * @var bool $more  more receipts wait than the window lists
 * @var \Z77\Module\Receipt\Entities\Receipt|null $single  the one receipt of «Zahlung suchen …»
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Receipt\Services\ReceiptBankMatch;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$window     = !empty($window);
$single     = $single ?? null;
$count      = count($rows);
$title      = $single !== null ? 'Zahlung suchen · Beleg ' . (int) $single->getId() : 'Zahlungen suchen · ' . ($count === 1 ? '1 Beleg' : $count . ' Belege');
$winAttr    = $window ? ' data-window="receipt-bank" data-window-entity="receipt-journal:bank" data-window-title="' . e($title) . '" data-window-width="56rem"' : '';
$flat       = static fn(string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));
$badges     = [
    ReceiptBankMatch::PROPOSAL   => ['badge--info', 'Vorschlag'],
    ReceiptBankMatch::CANDIDATES => ['badge--warning', 'zur Auswahl'],
];
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-bank') ?>" class="be-list__section" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $count > 0 ? 'Übernehmen' : '',
            'kind'       => 'confirm',
            'cancelHref' => $window ? '' : $actionBase . '/list',
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($count === 0): ?>
        <p class="be-list__empty" data-bank-empty><?= $single !== null ? 'Auf dem Kontoauszug passt keine freie Bewegung zu diesem Beleg.' : 'Auf dem Kontoauszug passt keine freie Bewegung zu einem Beleg.' ?></p>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <?php
        $receipt  = $row['receipt'];
        $match    = $row['match'];
        $rid      = (int) $receipt->getId();
        $proposed = $match->proposed();
        [$badgeClass, $badgeText] = $badges[$match->kind] ?? ['badge--muted', ''];
        ?>
        <fieldset class="be-form__fieldset" data-bank-receipt="<?= $rid ?>" data-bank-kind="<?= e($match->kind) ?>">
            <legend class="be-form__section">
                Beleg <?= $rid ?> · <?= e($receipt->getReceiptDate()->format('d.m.Y')) ?> · <?= $receipt->getText() !== '' ? e($receipt->getText()) : 'ohne Text' ?> · <?= e($fmt($receipt->getAmount())) ?>
                <?php if ($receipt->isPosted()): ?> · verbucht<?php endif; ?>
                <span class="badge <?= e($badgeClass) ?>"><?= e($badgeText) ?></span>
            </legend>
            <div class="be-list__table" style="--be-list-cols: 2rem 6.5rem minmax(12rem, 3fr) 7rem 7rem">
                <?php foreach ($match->movements as $movement): ?>
                <?php
                $mid   = (int) $movement->getId();
                $party = $movement->getCounterpartyName();
                $text  = $flat($movement->getText());
                $field = 'receipt-bank-' . $rid . '-' . $mid;
                ?>
                <div class="be-list__item" data-bank-movement="<?= $mid ?>"><div class="be-list__row">
                    <span class="be-list__cell"><label class="be-choice"><input type="radio" class="be-choice__input" id="<?= e($field) ?>" name="take[<?= $rid ?>]" value="<?= e($receipt->getVersion() . ':' . $mid) ?>"<?= $proposed !== null && (int) $proposed->getId() === $mid ? ' checked' : '' ?>></label></span>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($field) ?>"><?= e($movement->getBookingDate()->format('d.m.Y')) ?></label>
                    <label class="be-list__cell" for="<?= e($field) ?>" title="<?= e($text) ?>"><?= e($party !== '' ? $party : $text) ?><?php if ($party !== '' && $text !== ''): ?> <small class="be-list__cell--muted"><?= e($text) ?></small><?php endif; ?></label>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($movement->getAmount())) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--muted"><?= $movement->getOriginalAmount() !== null ? e($movement->getOriginalAmount() . ' ' . $movement->getOriginalCurrency()) : '' ?></span>
                </div></div>
                <?php endforeach; ?>
                <div class="be-list__item"><div class="be-list__row">
                    <span class="be-list__cell"><label class="be-choice"><input type="radio" class="be-choice__input" id="receipt-bank-<?= $rid ?>-none" name="take[<?= $rid ?>]" value=""<?= $proposed === null ? ' checked' : '' ?>></label></span>
                    <label class="be-list__cell be-list__cell--muted" for="receipt-bank-<?= $rid ?>-none">nicht zuordnen</label>
                    <span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span>
                </div></div>
            </div>
        </fieldset>
        <?php endforeach; ?>
        <?php if (!empty($more)): ?>
        <p class="be-form__hint" data-bank-more>Weitere Belege folgen nach dem Übernehmen.</p>
        <?php endif; ?>
    </form>
</div>
