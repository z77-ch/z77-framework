<?php
/**
 * Backend mount of the chart-of-accounts fragment (plan §5.1, ADR-018
 * pattern): pin the page body to the fragment's `listAction` template in
 * `module-financial`.
 */
return \Z77\Module\Financial\Ui\AccountLayout::config();
