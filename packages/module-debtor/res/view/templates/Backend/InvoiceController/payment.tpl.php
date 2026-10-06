<?php
/**
 * «Zahlung erfassen» / «Zahlung ändern» on a FINAL invoice (P4 part 1, plan
 * §6.3): the value date, the money that arrived and the LEDGER ACCOUNT it
 * went to (bank, cash register, a clearing account — module-mandator's
 * shared account datalist, the document's payment target proposed), the
 * Skonto granted, the amount written off, «Rest als Verlust ausbuchen», a
 * note. A PAGE with a plain form, no JavaScript (Rule 7); `csrf_token` is
 * the page-mode field (`#[Csrf]`); an edit carries the entity token and the
 * VERSION it was rendered from. The action bar (ADR-049) stands first. A
 * refusal of `PaymentService` comes back as the general error; what was
 * recorded is posted at once and shown on the detail. «Löschen …» (an
 * edit) leads to the confirmation page.
 *
 * @var \Z77\Module\Debtor\Ui\PaymentForm $form
 * @var \Z77\Module\Debtor\Entities\Invoice $document
 * @var \Z77\Module\Debtor\Entities\Payment|null $payment  edit only
 * @var \Z77\Shared\Money\Money $openAmount  what may still be allocated (on an edit: incl. this payment's own)
 * @var list<array{number: string, label: string}> $accounts
 * @var string $entityCsrf  edit only
 * @var callable $fmt
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$isEdit     = $payment !== null;
$action     = $isEdit ? $actionBase . '/payment?id=' . (int) $document->getId() . '&payment=' . (int) $payment->getId() : $actionBase . '/payment?id=' . (int) $document->getId();
$invalidIds = $form->invalidIds();
$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $message): string => $message !== '' ? 'true' : 'false';
?>
<div class="be-list">
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="payment-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($isEdit): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $payment->getVersion() ?>">
        <?php endif; ?>

        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm"><?= $isEdit ? 'Änderung verbuchen' : 'Erfassen und verbuchen' ?></button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/detail?id=' . (int) $document->getId()) ?>">Abbrechen</a>
            <?php if ($isEdit): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/confirm-payment-delete?id=' . (int) $document->getId() . '&payment=' . (int) $payment->getId()) ?>">Löschen …</a>
            <?php endif; ?>
            <?= $this->partial('partials/formErrorsLink', ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''], 'Z77\\Shared') ?>
        </div>

        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= $isEdit ? 'Zahlung ändern' : 'Zahlung erfassen' ?> <small class="be-list__cell--muted">· <?= e($document->documentName()) ?> · <?= e($document->getAddress()->getName()) ?> · offen <?= e($fmt($openAmount)) ?></small></h2>
        </div>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?= $this->partial('partials/accountDatalist', ['id' => 'payment-accounts', 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-date">Valuta</label>
                <input type="date" id="payment-date" name="date" value="<?= e($form->value('date')) ?>" required aria-invalid="<?= $invalid($form->error('date')) ?>">
                <?= raw($fieldError($form->error('date'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-payment">Zahlung <?= e($document->getCurrency()) ?></label>
                <input type="text" id="payment-payment" name="payment" value="<?= e($form->value('payment')) ?>" inputmode="decimal" placeholder="0.00" aria-invalid="<?= $invalid($form->error('payment')) ?>">
                <?= raw($fieldError($form->error('payment'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-account">Konto (Bank, Kasse, Ausbuchung)</label>
                <input type="text" id="payment-account" name="account" value="<?= e($form->value('account')) ?>"<?= $accounts !== [] ? ' list="payment-accounts"' : '' ?> inputmode="numeric" placeholder="1020" aria-invalid="<?= $invalid($form->error('account')) ?>">
                <?= raw($fieldError($form->error('account'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-discount">Skonto <?= e($document->getCurrency()) ?></label>
                <input type="text" id="payment-discount" name="discount" value="<?= e($form->value('discount')) ?>" inputmode="decimal" placeholder="0.00" aria-invalid="<?= $invalid($form->error('discount')) ?>">
                <?= raw($fieldError($form->error('discount'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-loss">Verlust <?= e($document->getCurrency()) ?></label>
                <input type="text" id="payment-loss" name="loss" value="<?= e($form->value('loss')) ?>" inputmode="decimal" placeholder="0.00" aria-invalid="<?= $invalid($form->error('loss')) ?>">
                <?= raw($fieldError($form->error('loss'))) ?>
            </div>
            <div class="be-form__field">
                <label class="be-choice">
                    <input type="checkbox" class="be-choice__input" name="rest_loss" value="1"<?= $form->value('rest_loss') !== '' ? ' checked' : '' ?>>
                    <span class="be-choice__label">Rest als Verlust ausbuchen — die Differenz zum offenen Betrag geht auf das Verlustkonto</span>
                </label>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-note">Bemerkung</label>
                <input type="text" id="payment-note" name="note" value="<?= e($form->value('note')) ?>" maxlength="140">
            </div>
        </div>
        <p class="be-form__hint">Skonto und Verlust kürzen Umsatz und MWST der Rechnung anteilig pro Steuercode. Alles wird sofort verbucht; eine Änderung ändert die Buchung mit, solange das Geschäftsjahr offen ist.</p>
    </form>
</div>
