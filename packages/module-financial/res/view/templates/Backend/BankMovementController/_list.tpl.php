<?php
/**
 * The list «Bewegungen» of ONE bank account — a STANDARD LIST over
 * {@see \Z77\Module\Financial\Ui\BankMovementListing} and a FETCH REGION (sort, search and page
 * reload only this part; a save in a window opened from here reloads it); also what an upload puts
 * in place of the old list. The header says how far the account is read: «eingelesen bis … ·
 * Saldo …».
 *
 * One line per movement: the selection checkbox (not on a «geschäftlich» row) · the state icon
 * (opens the movement's WINDOW, ADR-047) · Datum (the booking date of the bank) · Text
 * (counterparty, then what the bank wrote) · Betrag (signed as on the account) · Original (the
 * instructed amount of a foreign purchase) · Stand («zu prüfen» / «geschäftlich» / «privat» — the
 * DERIVED state, ADR-061 stage C2) · Zuordnung (what the movement is linked to — the owner's
 * label of its `BankLink`; empty = nothing) · Saldo (after the movement). The view (`state=`) is
 * kept through search, sort and paging.
 *
 * Above the list: the open questions of ADR-061 decision 8 (a document made a movement business
 * although its pattern is private — each a link into that movement's window, where it is
 * answered) and the SELECTION BAR (backend-screen.md §4): «Privat …» for the ticked rows — one GET
 * form (`data-window-form`: the answer opens as a window, the ticked rows travel by `form=`).
 * Every searchable cell is a `<label for>` of its search field.
 *
 * Bank text is FOREIGN INPUT: every value goes through `e()`. No JavaScript of its own.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount|null $account
 * @var \Z77\Module\Financial\Entities\BankStatement|null $latest  the newest statement — «eingelesen bis»
 * @var \Z77\Module\Financial\Entities\BankStatement|null $first
 * @var list<\Z77\Module\Financial\Entities\BankMovement> $movements
 * @var array<int, \Z77\Module\Financial\Entities\BankLink> $links  movement id → its link
 * @var array<int, string> $states  movement id → its state
 * @var list<array{movement: \Z77\Module\Financial\Entities\BankMovement, pattern: \Z77\Module\Financial\Entities\BankPattern, link: ?\Z77\Module\Financial\Entities\BankLink, others: int}> $questions
 * @var string|null $view  the state shown, null = all
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var string $actionBase
 */
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Ui\BankMovementListing;
use Z77\Shared\Money\AmountFormat;

$listUrl   = $actionBase . '/list';
$link      = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$flat      = static fn(string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));
$links     = $links ?? [];
$states    = $states ?? [];
$questions = $questions ?? [];
$view      = $view ?? null;
$region    = $definition->region();
$selection = BankMovementListing::SELECTION;
$words     = BankMovementListing::STATE_LABELS;
$icons     = [BankMovementRepository::STATE_REVIEW => 'editable', BankMovementRepository::STATE_BUSINESS => 'generated', BankMovementRepository::STATE_PRIVATE => 'closed'];
$titles    = [BankMovementRepository::STATE_REVIEW => 'Bewegungen zu prüfen', BankMovementRepository::STATE_BUSINESS => 'Geschäftliche Bewegungen', BankMovementRepository::STATE_PRIVATE => 'Private Bewegungen'];
$empties   = [BankMovementRepository::STATE_REVIEW => 'Keine Bewegung zu prüfen.', BankMovementRepository::STATE_BUSINESS => 'Keine geschäftliche Bewegung.', BankMovementRepository::STATE_PRIVATE => 'Keine private Bewegung.'];
$tickable  = array_filter($movements, static fn($m) => ($states[(int) $m->getId()] ?? '') !== BankMovementRepository::STATE_BUSINESS) !== [];
?>
<div class="be-list" data-fetch-region="<?= e($region) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl, 'keep' => array_filter(['account' => $state->extra('account'), 'state' => $state->extra('state')])], 'Z77\Shared') ?>
            <h2 class="be-list__section-title">
                <?= e($titles[(string) $view] ?? 'Bewegungen') ?>

                <small class="be-list__cell--muted">· <?= e((string) $account?->getLabel()) ?><?php if ($latest !== null): ?> · eingelesen bis <?= e($latest->getToDate()->format('d.m.Y')) ?> · Saldo <?= e(AmountFormat::of($latest->getClosingBalance())) ?><?php endif; ?></small>
            </h2>
            <span class="be-list__section-badge" title="Bewegungen"><?= $paging->total ?></span>
        </div>

        <?php foreach ($questions as $open): ?>
        <?php $openUrl = $actionBase . '/edit?id=' . (int) $open['movement']->getId(); ?>
        <div class="be-modal__alert be-modal__alert--error" role="status" data-pattern-question="<?= (int) $open['movement']->getId() ?>">
            <strong>«<?= e($open['pattern']->getLabel()) ?>» ist als privat gemerkt</strong> — die Bewegung vom <?= e($open['movement']->getBookingDate()->format('d.m.Y')) ?> ist geschäftlich<?php if ($open['link'] !== null): ?> (<?= e($open['link']->getOwnerLabel()) ?>)<?php endif; ?>.
            <a href="<?= e($openUrl) ?>" data-window-open="<?= e($openUrl) ?>" data-origin="region:<?= e($region) ?>">Entscheiden …</a>
        </div>
        <?php endforeach; ?>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
        <?php if ($tickable): ?>
        <form id="<?= e($selection) ?>" method="get" action="<?= e($actionBase . '/confirm-private') ?>" data-window-form></form>
        <div class="z77-form-actions" data-movement-selection>
            <button type="submit" form="<?= e($selection) ?>" class="be-btn be-btn--ghost be-btn--sm" title="Die angekreuzten Bewegungen als privat merken — du bestätigst im nächsten Schritt">Privat …</button>
        </div>
        <?php endif; ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($movements as $movement): ?>
                <?php
                $id     = (int) $movement->getId();
                $party  = $movement->getCounterpartyName();
                $text   = $flat($movement->getText());
                $original = $movement->getOriginalAmount() !== null ? $movement->getOriginalAmount() . ' ' . $movement->getOriginalCurrency() : '';
                $detail = trim(($party !== '' ? $text : '') . ($movement->getReference() !== '' ? ' · Ref. ' . $movement->getReference() : ''), ' ·');
                $linked = $links[$id] ?? null;
                $stand  = $states[$id] ?? BankMovementRepository::STATE_REVIEW;
                $url    = $actionBase . '/edit?id=' . $id;
                $open   = 'Bewegung öffnen — ' . ($words[$stand] ?? '');
                ?>
                <div class="be-list__item" data-movement-id="<?= $id ?>" data-movement-state="<?= e($stand) ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?php if ($stand !== BankMovementRepository::STATE_BUSINESS): ?><label class="be-choice"><input type="checkbox" class="be-choice__input" name="mv[]" form="<?= e($selection) ?>" value="<?= $id ?>" aria-label="Bewegung vom <?= e($movement->getBookingDate()->format('d.m.Y')) ?> auswählen"></label><?php endif; ?></span>
                        <a class="be-list__cell be-list__state be-list__state--<?= e($icons[$stand] ?? 'editable') ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($open) ?>" title="<?= e($open) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></a>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_date')) ?>"><?= e($movement->getBookingDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_text')) ?>" title="<?= e($text) ?>"><?= e($party !== '' ? $party : $text) ?><?php if ($detail !== ''): ?> <small class="be-list__cell--muted"><?= e($detail) ?></small><?php endif; ?></label>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= e(AmountFormat::of($movement->signedAmount())) ?></label>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($original) ?></span>
                        <?php if ($stand === BankMovementRepository::STATE_REVIEW): ?>
                        <strong class="be-list__cell"><?= e($words[$stand]) ?></strong>
                        <?php else: ?>
                        <span class="be-list__cell be-list__cell--muted"><?= e($words[$stand] ?? '') ?></span>
                        <?php endif; ?>
                        <span class="be-list__cell" data-priority="2" data-movement-link="<?= e((string) $linked?->getOwnerKey()) ?>"><?= e((string) $linked?->getOwnerLabel()) ?></span>
                        <span class="be-list__cell be-list__cell--num be-list__cell--muted" data-priority="3"><?= e(AmountFormat::of($movement->getBalance())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($movements === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Keine Bewegung gefunden.' : ($empties[(string) $view] ?? 'Noch kein Kontoauszug eingelesen.') ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Bewegungen',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
