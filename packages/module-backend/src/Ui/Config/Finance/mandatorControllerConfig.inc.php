<?php
/**
 * Backend mount of the Mandant fragment (owner decisions E1 / E2, ADR-018
 * pattern): pin the page body to the fragment's `edit` template in
 * `module-mandator` — one record, one page.
 */
return \Z77\Module\Mandator\Ui\MandatorLayout::config();
