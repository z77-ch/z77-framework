<?php
/**
 * Debitoren — the open-item list (2026-10-07, after wdv-630's «Debitoren»):
 * one row per FINAL payable document (invoice, fee). A STANDARD LIST
 * (listing.md): the head and the search form are the kernel's partials over
 * {@see \Z77\Module\Debtor\Ui\OpenItemListing}, the rows are this template's;
 * no CSS and no JavaScript of its own:
 *
 *   - the header carries the open total and its overdue part (wdv «OP-Total»)
 *     over ALL open documents, whatever the view or the search;
 *   - the state icon starts every row and opens the document detail as a
 *     WINDOW (ADR-047, `data-window-open`), the href stays for a ctrl-click;
 *   - every searchable cell is a `<label for>` of its column's search field;
 *   - «Zahlung» on an open row opens the document screen's payment form;
 *   - the whole list is a FETCH REGION: sort / page / search reload only
 *     this part.
 *
 * Status: «bezahlt» (nothing open), «überfällig» (open, due date passed),
 * «teilbezahlt» (something settled, the rest not yet due), «offen». The
 * dunning level stands beside it when the document carries a notice.
 *
 * @var list<\Z77\Module\Debtor\Repositories\OpenItem> $items
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var \DateTimeImmutable $today
 * @var \Z77\Shared\Money\Money $openTotal
 * @var \Z77\Shared\Money\Money $overdueTotal
 * @var int $openCount
 * @var int $overdueCount
 * @var array<string, string> $views
 * @var callable $fmt
 * @var string $actionBase
 * @var string $documentBase
 */
$actionBase   = $actionBase ?? '/backend/finance/debtor';
$documentBase = $documentBase ?? '/backend/finance/invoice';
$listUrl      = $actionBase . '/list';
$link         = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$view         = $state->extra('view');
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($views[$view] ?? '') ?></h2>
            <nav class="be-list__toggles" aria-label="Offene Posten">
                <span data-field="open-total" title="<?= (int) $openCount ?> offene Belege">OP-Total <strong><?= e($fmt($openTotal)) ?></strong></span>
                <span data-field="overdue-total" title="<?= (int) $overdueCount ?> überfällige Belege">davon überfällig <strong><?= e($fmt($overdueTotal)) ?></strong></span>
                <?php if ($state->isActive()): ?>
                <a data-fetch-region-link href="<?= e($listUrl . '?' . $state->resetQuery()) ?>">Suche zurücksetzen</a>
                <?php endif; ?>
            </nav>
            <span class="be-list__section-badge" title="Belege"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($items as $item): ?>
                <?php
                $detail  = $documentBase . '/detail?id=' . $item->id;
                $overdue = $item->isOverdue($today);
                [$label, $tone] = match (true) {
                    $item->isSettled()        => ['bezahlt', 'success'],
                    $overdue                  => ['überfällig', 'danger'],
                    !$item->settled->isZero() => ['teilbezahlt', 'info'],
                    default                   => ['offen', 'muted'],
                };
                ?>
                <div class="be-list__item" data-open-item="<?= e((string) $item->id) ?>">
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state be-list__state--closed" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>" aria-label="<?= e($item->documentName()) ?> öffnen" title="<?= e($item->documentName()) ?> öffnen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-lock"/></svg></a>
                        <span class="be-list__cell" data-field="status">
                            <span class="badge badge--<?= $tone ?>"><?= e($label) ?></span>
                            <?php if ($item->level > 0): ?><small class="be-list__cell--muted" title="Höchste Mahnstufe">M<?= $item->level ?></small><?php endif; ?>
                        </span>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_nr')) ?>" data-field="number"><?= $item->number ?><?= $item->kind->value === 'fee' ? ' <small class="be-list__cell--muted">Geb.</small>' : '' ?></label>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_customer')) ?>" data-priority="2" data-field="customer"><?= $item->customerNumber > 0 ? $item->customerNumber : '' ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_name')) ?>" data-field="name"><?= e($item->name) ?></label>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_gross')) ?>" data-priority="2" data-field="gross"><?= e($fmt($item->gross)) ?></label>
                        <span class="be-list__cell be-list__cell--num" data-priority="3" data-field="settled"><?= $item->settled->isZero() ? '' : e($fmt($item->settled)) ?></span>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_open')) ?>" data-field="open"><strong><?= e($fmt($item->open)) ?></strong></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_date')) ?>" data-priority="3" data-field="date"><?= e($item->invoiceDate->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_due')) ?>" data-field="due"><?= e($item->dueDate->format('d.m.Y')) ?></label>
                        <span class="be-list__cell">
                            <?php if (!$item->isSettled()): ?>
                            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($documentBase . '/payment?id=' . $item->id) ?>" title="Zahlung zu <?= e($item->documentName()) ?> erfassen">Zahlung</a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($items === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Beleg gefunden.' : ($view === 'all' ? 'Noch keine definitiven Belege.' : 'Keine offenen Posten.') ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Belege',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
