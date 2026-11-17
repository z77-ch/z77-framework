<?php
/**
 * The list «Bewegungen» of ONE bank account — a STANDARD LIST over
 * {@see \Z77\Module\Financial\Ui\BankMovementListing} and a FETCH REGION (sort, search and page
 * reload only this part); also what an upload puts in place of the old list. The header says how
 * far the account is read: «eingelesen bis … · Saldo …».
 *
 * One line per movement, no row window, no state (ADR-060 decision 17): Datum (the booking date
 * of the bank) · Text (counterparty, then what the bank wrote) · Betrag (signed as on the
 * account) · Original (the instructed amount of a foreign purchase) · Saldo (after the movement).
 * Every searchable cell is a `<label for>` of its search field.
 *
 * Bank text is FOREIGN INPUT: every value goes through `e()`.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount|null $account
 * @var \Z77\Module\Financial\Entities\BankStatement|null $latest  the newest statement — «eingelesen bis»
 * @var \Z77\Module\Financial\Entities\BankStatement|null $first
 * @var list<\Z77\Module\Financial\Entities\BankMovement> $movements
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var string $actionBase
 */
use Z77\Shared\Money\AmountFormat;

$listUrl = $actionBase . '/list';
$link    = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$flat    = static fn(string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl, 'keep' => ['account' => $state->extra('account')]], 'Z77\Shared') ?>
            <h2 class="be-list__section-title">
                Bewegungen
                <small class="be-list__cell--muted">· <?= e((string) $account?->getLabel()) ?><?php if ($latest !== null): ?> · eingelesen bis <?= e($latest->getToDate()->format('d.m.Y')) ?> · Saldo <?= e(AmountFormat::of($latest->getClosingBalance())) ?><?php endif; ?></small>
            </h2>
            <span class="be-list__section-badge" title="Bewegungen"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($movements as $movement): ?>
                <?php
                $party  = $movement->getCounterpartyName();
                $text   = $flat($movement->getText());
                $original = $movement->getOriginalAmount() !== null ? $movement->getOriginalAmount() . ' ' . $movement->getOriginalCurrency() : '';
                $detail = trim(($party !== '' ? $text : '') . ($movement->getReference() !== '' ? ' · Ref. ' . $movement->getReference() : ''), ' ·');
                ?>
                <div class="be-list__item" data-movement-id="<?= (int) $movement->getId() ?>">
                    <div class="be-list__row">
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_date')) ?>"><?= e($movement->getBookingDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_text')) ?>" title="<?= e($text) ?>"><?= e($party !== '' ? $party : $text) ?><?php if ($detail !== ''): ?> <small class="be-list__cell--muted"><?= e($detail) ?></small><?php endif; ?></label>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= e(AmountFormat::of($movement->signedAmount())) ?></label>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($original) ?></span>
                        <span class="be-list__cell be-list__cell--num be-list__cell--muted" data-priority="3"><?= e(AmountFormat::of($movement->getBalance())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($movements === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Keine Bewegung gefunden.' : 'Noch kein Kontoauszug eingelesen.' ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Bewegungen',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
