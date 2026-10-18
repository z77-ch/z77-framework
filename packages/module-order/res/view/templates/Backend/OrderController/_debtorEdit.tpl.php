<?php
/**
 * «Debitor anpassen» beside the label «Zahlungskonditionen» in block (3) (owner 2026-10-18): opens
 * debtor's own profile dialog (`DebtorProfileControllerTrait::editAction()`, `data-fetch-get` — a
 * popup over the order window) where the customer's standing terms are changed. In the link look
 * of «Kunde wechseln» (`be-form__card-action`, like «anpassen» on the address cards).
 *
 * The wrapper `#order-debtor-edit` is always rendered, so a customer choice can replace it
 * (`customerChosenCommands()`); it stays EMPTY without a customer and for a contact without a
 * debtor profile — debtor opens a profile only in the contact editor (role «Debitor») or with the
 * first invoice / booking print, which gives it the default terms.
 *
 * @var string $url  debtor's profile dialog of the customer, '' = no link
 */
?>
<span id="order-debtor-edit"><?php if ($url !== ''): ?><button type="button" class="be-form__card-action" data-fetch-get="<?= e($url) ?>">Debitor anpassen</button><?php endif; ?></span>
