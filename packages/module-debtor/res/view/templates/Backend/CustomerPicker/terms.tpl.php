<?php
/**
 * The answer of `customer-terms` (`CustomerPickerTrait::customerTermsAction()`): the head's
 * «Zahlungskonditionen» region alone — core.js reloads it when debtor's profile dialog («Debitor
 * anpassen») closes, so the first option names the terms just saved (`data-popup-refresh`).
 *
 * @var string $termsSelect  `_terms` with its region wrapper
 */
?>
<?= raw($termsSelect) ?>
