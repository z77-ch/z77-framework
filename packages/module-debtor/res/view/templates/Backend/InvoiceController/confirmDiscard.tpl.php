<?php
/**
 * «Entwurf löschen …» — the last look before a DRAFT is deleted (owner
 * 2026-10-13, review H3; ADR-040 addendum 2026-10-13): party, dates, amount.
 * One button POSTs to `discard` (`#[Csrf]`, the entity token `invoice:<id>`
 * and the VERSION shown — fetch.md confirm-delete pattern), which hands id +
 * version to `InvoicingService::discard()`: the draft and its lines are
 * deleted. Nothing else changes — a draft has no number and nothing posted.
 *
 * Fetched, the content of the detail WINDOW it is linked from
 * (`data-window-link`), so the delete answers into that window: it closes
 * and the list behind reloads. Without the script it is a page. The action
 * row (ADR-049, revision 2026-10-10) stands FIRST, like every other dialog
 * of this module.
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document  a draft (`invoicing`)
 * @var string $entityCsrf
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window  fetched as a window (ADR-047)
 * @var string $origin  where the window came from (WindowOrigin) — travels back as `_origin`
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$window     = !empty($window);
$winAttr    = $window
    ? ' data-window="invoice-discard" data-window-entity="invoice:' . (int) $document->getId() . '" data-window-title="' . e('Entwurf löschen · ' . $document->documentName()) . '"'
    : '';
$row        = static fn(string $label, string $text): string => '<div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">' . e($label) . '</span><span class="be-list__cell be-list__cell--wrap">' . e($text) . '</span></div></div>';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/discard?id=' . (int) $document->getId()) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $document->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Entwurf löschen',
            'kind'       => 'danger',
            'cancelHref' => $actionBase . '/detail?id=' . (int) $document->getId(),
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Entwurf löschen <small class="be-list__cell--muted">· <?= e($document->documentName()) ?></small></h2>
        </div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <?= raw($row('Debitor', $document->getContact()->displayName())) ?>
            <?= raw($row('Rechnungsdatum', $document->getInvoiceDate()->format('d.m.Y'))) ?>
            <?= raw($row('Betrag', $document->getCurrency() . ' ' . $fmt($document->getGrossTotal()))) ?>
            <?= raw($row('Positionen', (string) count($document->getLines()))) ?>
        </div>
        <p class="be-form__hint">Der Entwurf wird mit allen Positionen endgültig gelöscht. Er hat keine Belegnummer und es ist nichts verbucht — es entsteht keine Lücke und keine Buchung.</p>
    </form>
</div>
