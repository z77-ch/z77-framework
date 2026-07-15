<?php
namespace Z77\Module\Backend\Ui\Config;

/**
 * Per-controller layout override for the GUEST first-run setup page (LAYOUT-B001).
 *
 * Same as the login override: swaps the authenticated 3-column shell for the
 * chrome-less `html-guest-skeleton.tpl.php`. See `loginControllerConfig.inc.php`.
 *
 * Revert = delete this file; the setup page falls back to the shell skeleton.
 */
return [
    'documentTpl' => [
        'name'      => 'html-guest-skeleton',
        'nameSpace' => 'Z77\\Module\\Backend',
    ],
];
