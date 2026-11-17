<?php

namespace Z77\Module\Financial\Bank;

use Z77\Core\Services\ModuleManager;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The hints other modules have for a bank account — shown in the bank account window ONLY
 * (owner 2026-11-16). Every installed module may declare classes under `bankAccountHints`
 * ({@see BankAccountHintInterface}); a `::class` string in a config loads nothing until this
 * registry asks.
 */
final class BankAccountHints
{
    public const CONFIG_KEY = 'bankAccountHints';

    /** @param list<BankAccountHintInterface> $sources */
    public function __construct(private readonly array $sources = []) {}

    public static function fromModules(ModuleManager $modules): self
    {
        $sources = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            foreach ((array) ($modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []) ?? []) as $class) {
                if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, BankAccountHintInterface::class)) {
                    throw new \RuntimeException('❌ ' . self::CONFIG_KEY . " of module '{$moduleKey}' names " . (is_string($class) ? "'{$class}'" : get_debug_type($class))
                        . ' — expected a class implementing ' . BankAccountHintInterface::class . '.');
                }
                $sources[$class] ??= new $class();
            }
        }

        return new self(array_values($sources));
    }

    /** @return list<string> */
    public function hintsFor(BankAccount $account, UnifiedEntityManager $em): array
    {
        $hints = [];
        foreach ($this->sources as $source) {
            array_push($hints, ...$source->hintsFor($account, $em));
        }

        return $hints;
    }
}
