<?php

namespace Z77\Module\Financial\Bank;

use Z77\Core\Services\ModuleManager;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The registered {@see BankMovementClaimInterface}s — the union of every module's config key
 * `bankMovementClaims` (a list of classes), the `bankAccountHints` / `bankEntryOwners` pattern: a
 * module DECLARES, financial asks, nothing is scanned. A bad entry fails at registration, naming
 * its origin.
 */
final class BankMovementClaims
{
    public const CONFIG_KEY = 'bankMovementClaims';

    /** @var list<BankMovementClaimInterface> */
    private array $sources = [];

    /** @param list<class-string> $classes */
    public function __construct(array $classes = [])
    {
        $this->add($classes, self::CONFIG_KEY);
    }

    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self();
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            if ($declared !== null && $declared !== []) {
                $registry->add($declared, self::CONFIG_KEY . " of module '{$moduleKey}'");
            }
        }

        return $registry;
    }

    /** The sentence of the first module that claims $movement, or null when none does. */
    public function claimOn(BankMovement $movement, UnifiedEntityManager $em): ?string
    {
        foreach ($this->sources as $source) {
            $sentence = $source->claimOn($movement, $em);
            if ($sentence !== null && $sentence !== '') {
                return $sentence;
            }
        }

        return null;
    }

    private function add(mixed $classes, string $origin): void
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . BankMovementClaimInterface::class . ' classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, BankMovementClaimInterface::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . BankMovementClaimInterface::class . '.');
            }
            $this->sources[] = new $class();
        }
    }
}
