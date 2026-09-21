<?php
/**
 * Backend mount of the contact fragment (plan §4a, ADR-018 pattern): pin the
 * page body to the fragment's `listAction` template in `module-contact`. One-line
 * delegation — the layout lives with the fragment, not the host.
 */
return \Z77\Module\Contact\Ui\ContactLayout::config();
