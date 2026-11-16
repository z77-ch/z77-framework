<?php
/**
 * «Löschen …» for the rows ticked in «Im Journal» or «Gesperrt» (owner 2026-11-16) — a WINDOW
 * (the selection bar's GET form opens it through the button's `formaction`; without the script a
 * page): «47 Rechnungen löschen?», the invoices compactly (invoice, creditor, amount), the answer
 * in the action row. The POST carries the selection as hidden `inv[]` = `{id}:{version}` — the
 * version each row showed; a row changed since is not deleted and named in the answer. Every
 * invoice goes completely (blocked ones too): the PDF into the DMS trash, the same file may be
 * uploaded again.
 *
 * @var list<array{id: int, version: int}> $pairs
 * @var list<\Z77\Module\Creditor\Entities\CreditorInvoice> $invoices
 * @var array<string, string> $errors
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$count      = count($pairs);
$title      = ($count === 1 ? '1 Rechnung' : $count . ' Rechnungen') . ' löschen?';
$winAttr    = $window ? ' data-window="creditor-invoice-delete-selected" data-window-entity="creditor-journal:delete" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-delete-selected') ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php foreach ($pairs as $pair): ?>
        <input type="hidden" name="inv[]" value="<?= e($pair['id'] . ':' . $pair['version']) ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $count > 0 ? ($count === 1 ? 'Rechnung löschen' : $count . ' Rechnungen löschen') : '',
            'kind'       => 'danger',
            'cancelHref' => $window ? '' : $actionBase . '/list',
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($count === 0 || isset($errors['selection'])): ?>
        <div class="be-modal__alert be-modal__alert--error" data-delete-empty><?= e($errors['selection'] ?? 'Keine Rechnung angekreuzt — in der Liste die Rechnungen ankreuzen, die weg sollen, dann «Löschen …».') ?></div>
        <?php else: ?>
        <p class="be-form__hint">Die Rechnungen werden ganz gelöscht, die PDFs kommen in den Papierkorb der Dokumente. Dieselben Dateien kannst du danach wieder hochladen.</p>
        <div class="be-list__table" style="--be-list-cols: minmax(8rem, 1fr) minmax(9rem, 1.4fr) 7rem">
            <div class="be-list__head">
                <span class="be-list__col">Rechnung</span>
                <span class="be-list__col">Kreditor</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
            </div>
            <?php foreach ($invoices as $invoice): ?>
            <div class="be-list__item" data-delete-invoice="<?= (int) $invoice->getId() ?>"><div class="be-list__row">
                <span class="be-list__cell"><?= e($invoice->label()) ?><?php if ($invoice->isBlocked()): ?> <span class="badge badge--danger">Doppel?</span><?php endif; ?></span>
                <span class="be-list__cell"><?= e($invoice->getCreditor()?->getContact()?->displayName() ?? ($invoice->proposal()['name'] ?? '—')) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $invoice->getAmount() !== null ? e($fmt($invoice->getAmount())) : '—' ?><?php if ($invoice->isForeign()): ?><br><small class="be-list__cell--muted"><?= e((string) $invoice->originalLabel()) ?></small><?php endif; ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
