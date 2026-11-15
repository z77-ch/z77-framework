<?php
/**
 * «Bezahlt am …» for the rows ticked in «Im Journal» (creditor plan §6) — a WINDOW (the selection
 * bar's GET form `data-window-form` opens it; without the script a page). It lists the invoices
 * (number, creditor, amount, «bezahlt am» so far), asks the payment date (proposed today, never in
 * the future — the posting date) and optionally «Bezahlt mit» for all of them (empty = each keeps
 * its own). The POST carries the selection as hidden `inv[]` = `{id}:{version}` — the version each
 * row showed; a row changed since is not written and named in the answer. Nothing is posted here:
 * the bookkeeping follows with «Journal verbuchen …».
 *
 * @var list<array{id: int, version: int}> $pairs
 * @var list<\Z77\Module\Creditor\Entities\CreditorInvoice> $invoices
 * @var array{paid_on: string, payment_method_code: string} $values
 * @var array<string, string> $errors
 * @var list<\Z77\Module\Receipt\Entities\PaymentMethod> $methods  the active ones
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$count      = count($pairs);
$title      = 'Bezahlt am … · ' . ($count === 1 ? '1 Rechnung' : $count . ' Rechnungen');
$winAttr    = $window ? ' data-window="creditor-invoice-paid" data-window-entity="creditor-journal:paid" data-window-title="' . e($title) . '" data-window-width="44rem"' : '';
$fieldError = static fn(string $field): string => isset($errors[$field]) ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$field]) . '</small>' : '';
$errCount   = (int) isset($errors['paid_on']) + (int) isset($errors['payment_method_code']);
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-paid') ?>" class="be-list__section" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php foreach ($pairs as $pair): ?>
        <input type="hidden" name="inv[]" value="<?= e($pair['id'] . ':' . $pair['version']) ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $count > 0 ? 'Als bezahlt markieren' : '',
            'kind'       => 'confirm',
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => $errCount, 'target' => isset($errors['paid_on']) ? 'ci-paid-on' : (isset($errors['payment_method_code']) ? 'ci-paid-method' : '')],
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($count === 0): ?>
        <div class="be-modal__alert be-modal__alert--error" data-paid-empty>Keine Rechnung angekreuzt — in der Liste «Im Journal» die bezahlten Rechnungen ankreuzen, dann «Bezahlt am …».</div>
        <?php else: ?>
        <?php if (isset($errors['selection'])): ?><div class="be-modal__alert be-modal__alert--error"><?= e($errors['selection']) ?></div><?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="ci-paid-on">Bezahlt am</label>
                <input type="date" id="ci-paid-on" name="paid_on" value="<?= e($values['paid_on']) ?>" max="<?= e((new \DateTimeImmutable('today'))->format('Y-m-d')) ?>" aria-invalid="<?= isset($errors['paid_on']) ? 'true' : 'false' ?>" required>
                <?= raw($fieldError('paid_on')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="ci-paid-method">Bezahlt mit</label>
                <select id="ci-paid-method" name="payment_method_code" aria-invalid="<?= isset($errors['payment_method_code']) ? 'true' : 'false' ?>">
                    <option value=""<?= $values['payment_method_code'] === '' ? ' selected' : '' ?>>— unverändert —</option>
                    <?php foreach ($methods as $m): ?>
                    <option value="<?= e($m->getCode()) ?>"<?= $m->getCode() === $values['payment_method_code'] ? ' selected' : '' ?>><?= e($m->getLabel()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('payment_method_code')) ?>
            </div>
        </div>
        <div class="be-list__table" style="--be-list-cols: minmax(8rem, 1fr) minmax(9rem, 1.4fr) 7rem 6.5rem">
            <div class="be-list__head">
                <span class="be-list__col">Rechnung</span>
                <span class="be-list__col">Kreditor</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
                <span class="be-list__col">bisher</span>
            </div>
            <?php foreach ($invoices as $invoice): ?>
            <div class="be-list__item" data-paid-invoice="<?= (int) $invoice->getId() ?>"><div class="be-list__row">
                <span class="be-list__cell"><?= e($invoice->label()) ?></span>
                <span class="be-list__cell"><?= e($invoice->getCreditor()?->getContact()?->displayName() ?? '—') ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $invoice->getAmount() !== null ? e($fmt($invoice->getAmount())) : '—' ?></span>
                <span class="be-list__cell be-list__cell--mono"><?= $invoice->getPaidOn() !== null ? e($invoice->getPaidOn()->format('d.m.Y')) : '<span class="be-list__cell--muted">unbezahlt</span>' ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Gebucht wird noch nicht: «Journal verbuchen …» bringt die bezahlten Rechnungen mit diesem Datum in die Buchhaltung.</p>
        <?php endif; ?>
    </form>
</div>
