<?php
/**
 * The customer of a document whose party cannot change any more — in the look of the field
 * (`_field`: the name as text) without «Kunde wechseln»: the order window read-only, the invoice
 * editor's «Bearbeiten» and credit note (shared since 2026-10-19). `#invoice-contact-name` stays
 * the target of an error link, as in the field.
 *
 * @var string $name  the customer's display name
 */
?>
<span class="be-customer"><span class="be-customer__who"><strong class="be-customer__name" id="invoice-contact-name" tabindex="-1"><?= e($name) ?></strong></span></span>
