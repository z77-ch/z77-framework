<?php
namespace Z77\Module\Backend\Ui\Config;

/**
 * Per-controller layout override for the GUEST login page (LAYOUT-B001).
 *
 * Applied on top of the module `Ui/Config/layoutConfig.inc.php`, so it only
 * swaps the skeleton: the authenticated 3-column shell is replaced by the
 * chrome-less `html-guest-skeleton.tpl.php` (no topbar/subnav/preview). The
 * module still registers the chrome partials (config is append-only) — they
 * render empty for a GUEST and are not echoed by the guest skeleton.
 *
 * Revert = delete this file; the login page falls back to the shell skeleton.
 */
return [
    'documentTpl' => [
        'name'      => 'html-guest-skeleton',
        'nameSpace' => 'Z77\\Module\\Backend',
    ],
];
