<?php
/**
 * «Zahlungen suchen …» (toolbar of the Kreditorenjournal) and «Zahlung suchen …» (one invoice's
 * window) — ADR-061 decision 11, stage C1 — a WINDOW (without the script a page). Per invoice the
 * bank movements that fit, as ONE radio group `take[{invoice id}]` = `{version}:{movement id}`
 * (the version the window showed) plus «nicht zuordnen» (empty):
 *
 *   - an identity (the reference) or a proposal → its movement is TICKED IN ADVANCE; the office
 *     confirms several at once with «Übernehmen»;
 *   - candidates → nothing ticked («nicht zuordnen» is), the office picks — never a guess.
 *
 * Nothing is decided here: the POST hands each ticked pair to
 * `CreditorBankMatchService::take()`, which judges basis and pair again on the server. Labels
 * only — the explanation is the help section `bank` (backend-screen.md §5). Bank text is foreign
 * input: every value goes through `e()`.
 *
 * @var list<array{invoice: \Z77\Module\Creditor\Entities\CreditorInvoice, match: \Z77\Module\Creditor\Services\CreditorBankMatch}> $rows
 * @var bool $more  more invoices wait than the window lists
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice|null $single  the one invoice of «Zahlung suchen …»
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Creditor\Services\CreditorBankMatch;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$single     = $single ?? null;
$count      = count($rows);
$title      = $single !== null ? 'Zahlung suchen · ' . $single->label() : 'Zahlungen suchen · ' . ($count === 1 ? '1 Rechnung' : $count . ' Rechnungen');
$winAttr    = $window ? ' data-window="creditor-invoice-bank" data-window-entity="creditor-journal:bank" data-window-title="' . e($title) . '" data-window-width="56rem"' : '';
$flat       = static fn(string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));
$badges     = [
    CreditorBankMatch::IDENTITY   => ['badge--success', 'Referenz'],
    CreditorBankMatch::PROPOSAL   => ['badge--info', 'Vorschlag'],
    CreditorBankMatch::CANDIDATES => ['badge--warning', 'zur Auswahl'],
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
        <p class="be-list__empty" data-bank-empty><?= $single !== null ? 'Auf dem Kontoauszug passt keine freie Bewegung zu dieser Rechnung.' : 'Auf dem Kontoauszug passt keine freie Bewegung zu einer Rechnung.' ?></p>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <?php
        $invoice  = $row['invoice'];
        $match    = $row['match'];
        $iid      = (int) $invoice->getId();
        $proposed = $match->proposed();
        $creditor = $invoice->getCreditor()?->getContact()?->displayName() ?? '—';
        [$badgeClass, $badgeText] = $badges[$match->kind] ?? ['badge--muted', ''];
        ?>
        <fieldset class="be-form__fieldset" data-bank-invoice="<?= $iid ?>" data-bank-kind="<?= e($match->kind) ?>">
            <legend class="be-form__section">
                <?= e($invoice->label()) ?> · <?= e($creditor) ?> ·
                <?= $invoice->isForeign() ? e((string) $invoice->originalLabel()) : ($invoice->getAmount() !== null ? e($fmt($invoice->getAmount())) : '—') ?>
                <?php if ($invoice->getPaidOn() !== null): ?> · bezahlt <?= e($invoice->getPaidOn()->format('d.m.Y')) ?><?php elseif ($invoice->getDueDate() !== null): ?> · fällig <?= e($invoice->getDueDate()->format('d.m.Y')) ?><?php endif; ?>
                <?php if ($invoice->isPosted()): ?> · verbucht<?php endif; ?>
                <span class="badge <?= e($badgeClass) ?>"><?= e($badgeText) ?></span>
            </legend>
            <div class="be-list__table" style="--be-list-cols: 2rem 6.5rem minmax(12rem, 3fr) 7rem 7rem">
                <?php foreach ($match->movements as $movement): ?>
                <?php
                $mid   = (int) $movement->getId();
                $party = $movement->getCounterpartyName();
                $text  = $flat($movement->getText());
                $field = 'ci-bank-' . $iid . '-' . $mid;
                ?>
                <div class="be-list__item" data-bank-movement="<?= $mid ?>"><div class="be-list__row">
                    <span class="be-list__cell"><label class="be-choice"><input type="radio" class="be-choice__input" id="<?= e($field) ?>" name="take[<?= $iid ?>]" value="<?= e($invoice->getVersion() . ':' . $mid) ?>"<?= $proposed !== null && (int) $proposed->getId() === $mid ? ' checked' : '' ?>></label></span>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($field) ?>"><?= e($movement->getBookingDate()->format('d.m.Y')) ?></label>
                    <label class="be-list__cell" for="<?= e($field) ?>" title="<?= e($text) ?>"><?= e($party !== '' ? $party : $text) ?><?php if ($party !== '' && $text !== ''): ?> <small class="be-list__cell--muted"><?= e($text) ?></small><?php endif; ?></label>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($movement->getAmount())) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--muted"><?= $movement->getOriginalAmount() !== null ? e($movement->getOriginalAmount() . ' ' . $movement->getOriginalCurrency()) : '' ?></span>
                </div></div>
                <?php endforeach; ?>
                <div class="be-list__item"><div class="be-list__row">
                    <span class="be-list__cell"><label class="be-choice"><input type="radio" class="be-choice__input" id="ci-bank-<?= $iid ?>-none" name="take[<?= $iid ?>]" value=""<?= $proposed === null ? ' checked' : '' ?>></label></span>
                    <label class="be-list__cell be-list__cell--muted" for="ci-bank-<?= $iid ?>-none">nicht zuordnen</label>
                    <span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span>
                </div></div>
            </div>
        </fieldset>
        <?php endforeach; ?>
        <?php if (!empty($more)): ?>
        <p class="be-form__hint" data-bank-more>Weitere Rechnungen folgen nach dem Übernehmen.</p>
        <?php endif; ?>
    </form>
</div>
