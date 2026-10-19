<?php
/**
 * «Debitor anpassen» beside the label «Zahlungskonditionen» of a document editor's head (owner
 * 2026-10-18; shared since 2026-10-19 — the order window and the invoice editor): opens debtor's
 * own profile dialog (`DebtorProfileControllerTrait::editAction()`, `data-fetch-get` — a popup
 * over the editor window) where the customer's standing terms are changed; when it closes, the
 * terms region `{prefix}-terms` reloads (`data-popup-refresh`, `_terms`). In the link look of
 * «Kunde wechseln» (`be-form__card-action`).
 *
 * The wrapper `#{prefix}-debtor-edit` is always rendered, so a customer choice can replace it
 * (`CustomerPickerTrait::customerChosenAnswer()`); it stays EMPTY without a customer and for a
 * contact without a debtor profile (an older order).
 *
 * @var string $prefix  the host's id prefix ('order', 'invoice')
 * @var string $url     debtor's profile dialog of the customer, '' = no link
 */
?>
<span id="<?= e($prefix) ?>-debtor-edit"><?php if ($url !== ''): ?><button type="button" class="be-form__card-action" data-fetch-get="<?= e($url) ?>" data-popup-refresh="<?= e($prefix) ?>-terms">Debitor anpassen</button><?php endif; ?></span>
