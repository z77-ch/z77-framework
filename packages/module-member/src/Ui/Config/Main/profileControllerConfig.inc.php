<?php
namespace Z77\Module\Member\Ui\Config\Main;

/**
 * Layout of the PROFILE controller — the first screen behind the login, and
 * therefore the first that gets the shell instead of the card.
 *
 * Controller-level config: the module config runs first and sets the card
 * skeleton, this one replaces the skeleton and adds the two shell partials.
 * Login, register, confirm and the waiting page never load this file and keep
 * the card — which is the whole point of putting it here instead of module-wide.
 *
 * ⚠️ The file name follows the CLASS, not the URL segment:
 * `{Group}/{lcfirst(ClassBaseName)}Config.inc.php`, so `ProfileController`
 * needs `profileControllerConfig.inc.php`. Named `profileConfig.inc.php` it is
 * never found and the page silently keeps the module skeleton — no error, just
 * the wrong layout. (create-page.md writes the rule short; the backend's
 * `System/loginControllerConfig.inc.php` shows it in full.)
 *
 * A project that adds its own signed-in controllers writes the same file for
 * them (AXO3: `Main/verwaltungControllerConfig.inc.php` in the override).
 */
return [
    'documentTpl' => [
        'name'      => 'html-shell-skeleton',
        'nameSpace' => 'Z77\\Module\\Member',
    ],
    'levelElements' => [
        'body' => [
            'shellHeader' => 'partials/shell/header',
            'shellFooter' => 'partials/shell/footer',
        ],
    ],
    // The shell's own behaviour: the account panel and the appearance switch.
    // It belongs to the same decision as the two partials above — a controller
    // that wears the shell gets its script, the door pages never load it.
    // Controller configs ADD to the module config, they do not replace it.
    'javascripts' => [
        ['nameSpace' => 'Z77\\Module\\Member', 'name' => 'shell', 'position' => 'footer'],
        // The work area's drag handle and narrow-screen overlays — the shared
        // primitive's own script (kernel/shared), same file the backend and
        // the DMS use.
        ['nameSpace' => 'Z77\\Shared', 'name' => 'split', 'position' => 'footer'],
    ],
];
