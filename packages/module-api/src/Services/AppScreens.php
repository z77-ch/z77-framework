<?php

namespace Z77\Module\Api\Services;

use Z77\Core\Services\ModuleManager;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * The screens of the app shell `/app` (plan A6 / T6): collected from the module configs, the
 * declaration pattern of `apiServices`. The shell is generic — it pairs the device, asks the
 * gateway what the device may do, and shows each screen whose scope the device holds; the module
 * that owns a target contributes the screen:
 *
 *   // module-receipt's receiptConfig.inc.php
 *   'appScreens' => [
 *       'receipt' => [
 *           'scope'    => 'receipt.capture',   // the device scope that shows it
 *           'title'    => 'Belege',            // the bar's title while it is the only screen
 *           'template' => 'App/receipt',       // a template of THIS module (res/view/templates)
 *           'js'       => ['app/receipt'],     // scripts of THIS module (res/assets/js), optional
 *       ],
 *   ],
 *
 * The scope only decides what the shell SHOWS; the gateway decides what the device may DO.
 * A bad key, a missing scope or template, or a screen declared by two modules is a config error
 * and throws (fail fast, like `apiServices`).
 */
final class AppScreens
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    /** @var list<array{key: string, scope: string, title: string, template: string, js: list<string>, nameSpace: string}>|null */
    private ?array $declared = null;

    public function __construct(private readonly ModuleManager $moduleManager)
    {
    }

    /** @return list<array{key: string, scope: string, title: string, template: string, js: list<string>, nameSpace: string}> */
    public function all(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $screens = [];
        foreach ($this->moduleManager->getModuleKeys() as $moduleKey) {
            $declared = $this->moduleManager->getModuleConfig($moduleKey)?->get('appScreens', []) ?? [];
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $key => $screen) {
                if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                    throw new \LogicException('appScreens: invalid screen key ' . var_export($key, true) . " in module '{$moduleKey}'.");
                }
                if (isset($screens[$key])) {
                    throw new \LogicException("appScreens: screen '{$key}' is declared by more than one module.");
                }
                $scope    = is_array($screen) ? (string) ($screen['scope'] ?? '') : '';
                $template = is_array($screen) ? (string) ($screen['template'] ?? '') : '';
                if (preg_match(ApiDevices::SCOPE_PATTERN, $scope) !== 1 || $template === '') {
                    throw new \LogicException("appScreens: screen '{$key}' needs a valid `scope` and a `template`.");
                }
                $screens[$key] = [
                    'key'       => $key,
                    'scope'     => $scope,
                    'title'     => trim((string) ($screen['title'] ?? '')),
                    'template'  => $template,
                    'js'        => array_values(array_filter((array) ($screen['js'] ?? []), 'is_string')),
                    'nameSpace' => Naming::toNamespaceString(['Z77', 'Module', $moduleKey]),
                ];
            }
        }

        return $this->declared = array_values($screens);
    }
}
