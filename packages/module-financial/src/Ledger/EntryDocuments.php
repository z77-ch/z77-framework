<?php

namespace Z77\Module\Financial\Ledger;

use Z77\Core\Services\ModuleManager;

/**
 * The registered {@see EntryDocumentProviderInterface}s — the union of every module's config key
 * `entryDocuments` (a list of classes) and its extension files
 * `App/Config/entryDocumentsConfig.inc.php`, the `BankEntryOwners` / `OpenWorkChecks` pattern: a
 * module DECLARES, financial asks, nothing is scanned. A bad entry fails at registration, naming
 * its origin.
 */
final class EntryDocuments
{
    public const CONFIG_KEY = 'entryDocuments';

    /** @var list<class-string<EntryDocumentProviderInterface>> */
    private array $classes = [];

    /** @param list<class-string> $classes */
    public function __construct(array $classes)
    {
        $this->add($classes, self::CONFIG_KEY);
    }

    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self([]);
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
            foreach ($sources as $origin => $classes) {
                $registry->add($classes, $origin);
            }
        }

        return $registry;
    }

    /**
     * Every registered module's answer for $entries, merged — the first module that names an
     * entry wins (an entry has one origin). Only entries WITH an origin are passed on; an answer
     * that is not an {@see EntryDocument} is dropped.
     *
     * @param list<array{id: int, sourceType: ?string, sourceRef: ?string}> $entries
     * @return array<int, EntryDocument> journal entry id → its document
     */
    public function documentsFor(array $entries): array
    {
        $generated = [];
        foreach ($entries as $entry) {
            if (($entry['sourceType'] ?? null) !== null && ($entry['sourceRef'] ?? null) !== null) {
                $generated[] = ['id' => (int) $entry['id'], 'sourceType' => (string) $entry['sourceType'], 'sourceRef' => (string) $entry['sourceRef']];
            }
        }
        $out = [];
        if ($generated === []) {
            return $out;
        }
        foreach ($this->classes as $class) {
            foreach ((new $class())->documentsFor($generated) as $entryId => $document) {
                if ($document instanceof EntryDocument) {
                    $out[(int) $entryId] ??= $document;
                }
            }
        }

        return $out;
    }

    private function add(mixed $classes, string $origin): void
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . EntryDocumentProviderInterface::class . ' classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, EntryDocumentProviderInterface::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . EntryDocumentProviderInterface::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which cannot be instantiated without arguments — it reaches its services through DI.");
            }
            if (!in_array($class, $this->classes, true)) {
                $this->classes[] = $class;
            }
        }
    }
}
