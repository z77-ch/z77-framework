<?php
/**
 * «Privat …» for the rows ticked in the list «Bewegungen» (ADR-061 stage C2) — a WINDOW (the
 * selection bar's GET form opens it; without the script a page): the movements compactly (date,
 * text, amount, state), the answer in the action row. The POST carries the selection as hidden
 * `mv[]`; each movement is marked as the single «Privat» does — the pattern where it has one
 * (`BankMovementStates::markPrivateMany()`); a «geschäftlich» one is left and counted in the
 * answer. Labels only; bank text goes through `e()`.
 *
 * @var list<\Z77\Module\Financial\Entities\BankMovement> $movements
 * @var array<int, string> $states  movement id → its state
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Financial\Ui\BankMovementListing;
use Z77\Shared\Money\AmountFormat;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::MOVEMENT_BASE;
$window     = !empty($window);
$count      = count($movements);
$title      = ($count === 1 ? '1 Bewegung' : $count . ' Bewegungen') . ' privat?';
$flat       = static fn(string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));
$winAttr    = $window ? ' data-window="bank-movement-private" data-window-entity="bank-movement:private" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-private') ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php foreach ($movements as $movement): ?>
        <input type="hidden" name="mv[]" value="<?= (int) $movement->getId() ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $count > 0 ? 'Privat' : '',
            'cancelHref' => $window ? '' : $actionBase . '/list',
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($count === 0): ?>
        <div class="be-modal__alert be-modal__alert--error" data-private-empty>Keine Bewegung angekreuzt.</div>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(10rem, 1fr) 7rem 7rem">
            <div class="be-list__head">
                <span class="be-list__col">Datum</span>
                <span class="be-list__col">Text</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
                <span class="be-list__col">Stand</span>
            </div>
            <?php foreach ($movements as $movement): ?>
            <?php $party = $movement->getCounterpartyName(); ?>
            <div class="be-list__item" data-private-movement="<?= (int) $movement->getId() ?>"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e($movement->getBookingDate()->format('d.m.Y')) ?></span>
                <span class="be-list__cell"><?= e($party !== '' ? $party : $flat($movement->getText())) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(AmountFormat::of($movement->signedAmount())) ?></span>
                <span class="be-list__cell be-list__cell--muted"><?= e(BankMovementListing::STATE_LABELS[$states[(int) $movement->getId()] ?? ''] ?? '') ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
