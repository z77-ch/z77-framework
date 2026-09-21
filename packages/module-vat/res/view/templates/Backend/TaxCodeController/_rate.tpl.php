<?php
/**
 * One rate as a phrase: «8.1 % ab 01.01.2024». The single place that formats
 * a rate row for the screen (list, actions hub, confirm dialog) — Rule 8.
 *
 * @var \Z77\Module\Vat\Entities\TaxRate $rate
 */
use Z77\Module\Vat\Entities\TaxRate;

$iso = $rate->getValidFrom();
?><?= e(TaxRate::formatPercent($rate->getRate())) ?> % ab <?= e(substr($iso, 8, 2) . '.' . substr($iso, 5, 2) . '.' . substr($iso, 0, 4)) ?>