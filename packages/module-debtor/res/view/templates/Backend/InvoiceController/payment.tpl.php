<?php
/**
 * «Zahlung erfassen» on a FINAL invoice (P4 part 1, plan §6.3): the value
 * date, the money that arrived (on which payment target), the Skonto
 * granted, the amount written off — at least one of the three, together
 * at most the open amount. A PAGE with a plain form, no JavaScript
 * (Rule 7); `csrf_token` is the page-mode field (`#[Csrf]`). The action
 * bar (ADR-049) stands first. A refusal of `PaymentService` comes back as
 * the general error; what was recorded is posted at once and shown on the
 * detail.
 *
 * @var \Z77\Module\Debtor\Ui\PaymentForm $form
 * @var \Z77\Module\Debtor\Entities\Invoice $document
 * @var \Z77\Shared\Money\Money $openAmount
 * @var list<\Z77\Module\Debtor\Entities\PaymentTarget> $targets  active ones
 * @var callable $fmt
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$invalidIds = $form->invalidIds();
$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $message): string => $message !== '' ? 'true' : 'false';
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase . '/payment?id=' . (int) $document->getId()) ?>" class="be-list__section" id="payment-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">

        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm">Erfassen und verbuchen</button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/detail?id=' . (int) $document->getId()) ?>">Abbrechen</a>
            <?= $this->partial('partials/formErrorsLink', ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''], 'Z77\\Shared') ?>
        </div>

        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlung erfassen <small class="be-list__cell--muted">· <?= e($document->documentName()) ?> · <?= e($document->getAddress()->getName()) ?> · offen <?= e($fmt($openAmount)) ?></small></h2>
        </div>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

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
                <label for="payment-target">Zahlungsziel (Bankkonto)</label>
                <select id="payment-target" name="target" aria-invalid="<?= $invalid($form->error('target')) ?>">
                    <option value="">– ohne Zahlung –</option>
                    <?php foreach ($targets as $target): ?>
                    <option value="<?= e($target->getCode()) ?>"<?= $target->getCode() === $form->value('target') ? ' selected' : '' ?>><?= e($target->getLabel()) ?><?= trim($target->getAccountNumber()) !== '' ? ' · Konto ' . e($target->getAccountNumber()) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError($form->error('target'))) ?>
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
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-note">Bemerkung</label>
                <input type="text" id="payment-note" name="note" value="<?= e($form->value('note')) ?>" maxlength="140">
            </div>
        </div>
        <p class="be-form__hint">Skonto und Verlust kürzen Umsatz und MWST der Rechnung anteilig pro Steuercode und werden sofort verbucht.</p>
    </form>
</div>
