<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\Services\ModuleManager;

/**
 * The registry of {@see InvoicePostedListener}s (owner 2026-10-24) — the pattern of
 * `openWorkChecks` and `mailAttachments`: a module DECLARES under its config key
 * `invoicePostedListeners` a list of listener classes; debtor collects the union across all modules
 * (and every extension file `App/Config/invoicePostedListenersConfig.inc.php`,
 * `ModuleManager::getConfigExtensions()`). A bad entry fails at the first use, naming its origin.
 *
 * ```php
 * // module-order: orderConfig.inc.php
 * 'invoicePostedListeners' => [OrderInvoicePostedListener::class],
 * ```
 */
final class InvoicePostedListeners
{
    public const CONFIG_KEY = 'invoicePostedListeners';

    /** @return list<InvoicePostedListener> in module order, each class once */
    public static function fromModules(ModuleManager $modules): array
    {
        $classes = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
            foreach ($sources as $origin => $list) {
                foreach (self::checked($list, $origin) as $class) {
                    $classes[$class] = true;
                }
            }
        }

        return array_map(static fn(string $class) => new $class(), array_keys($classes));
    }

    /** @return list<class-string<InvoicePostedListener>> */
    private static function checked(mixed $list, string $origin): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . InvoicePostedListener::class . ' classes.');
        }
        foreach ($list as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, InvoicePostedListener::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . InvoicePostedListener::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which cannot be instantiated without arguments.");
            }
        }

        return $list;
    }
}
