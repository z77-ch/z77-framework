<?php
/**
 * One typed address as a phrase: «Rechnungsadresse · Musterstrasse 12, 8000 Zürich»
 * (plus the link's title when set). The single place that phrases a
 * contact-address row for the screen (list, actions hub, confirm dialog) —
 * Rule 8. The type label is resolved through `AddressTypes::labelOf()`, which
 * shows the raw code when the type row is gone instead of failing the page.
 *
 * @var \Z77\Module\Contact\Entities\ContactAddress $link
 * @var \Z77\Module\Contact\Services\AddressTypes $addressTypes
 */
$type  = $addressTypes->labelOf($link->getTypeCode());
$title = $link->getTitle();
?><span class="be-list__cell--muted"><?= e($type) ?><?= $title !== '' ? ' «' . e($title) . '»' : '' ?> ·</span> <?= e($link->getAddress()->oneLine()) ?>
