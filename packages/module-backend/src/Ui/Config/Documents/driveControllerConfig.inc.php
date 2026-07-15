<?php
/**
 * Backend mount of the DMS Drive fragment (ADR-018 / ADR-019): pin the page body to the
 * Drive's `listAction` template in `module-dms`. One-line delegation — the layout lives
 * with the fragment, not the host.
 */
return \Z77\Module\Dms\Ui\DriveLayout::config();
