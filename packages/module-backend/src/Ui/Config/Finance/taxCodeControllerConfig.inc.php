<?php
/**
 * Backend mount of the tax-code fragment (ADR-041, ADR-018 pattern): pin the
 * page body to the fragment's `listAction` template in `module-vat`. One-line
 * delegation — the layout lives with the fragment, not the host.
 */
return \Z77\Module\Vat\Ui\TaxCodeLayout::config();
