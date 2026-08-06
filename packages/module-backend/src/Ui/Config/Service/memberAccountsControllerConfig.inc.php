<?php
/**
 * Backend mount of the member accounts fragment (B7, ADR-018 pattern): pin the
 * page body to the fragment's `listAction` template in `module-member`. One-line
 * delegation — the layout lives with the fragment, not the host.
 */
return \Z77\Module\Member\Ui\AccountsLayout::config();
