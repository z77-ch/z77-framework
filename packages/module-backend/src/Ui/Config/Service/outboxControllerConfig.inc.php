<?php
/**
 * Backend mount of the mail outbox fragment (Service › Postausgang, ADR-018
 * pattern): pin the page body to the fragment's `listAction` template in
 * `module-mailing`.
 */
return \Z77\Module\Mailing\Ui\OutboxLayout::config();
