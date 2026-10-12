<?php

namespace Z77\Module\Contact\Roles;

use Z77\Core\Services\ModuleManager;

/**
 * The contact-role registry (owner decision 2026-10-12, Rule 9): the roles OTHER
 * modules give a contact, collected from their configuration — the pattern of the
 * open-work registry (`openWorkChecks`, persistence-doctrine) and `statsEvents`.
 * module-contact asks it and renders what it finds; it names no module.
 *
 * A module declares under its config key `contactRoles` which class answers which
 * role key:
 *
 * ```php
 * // module-debtor: debtorConfig.inc.php
 * 'contactRoles' => [
 *     'debtor' => DebtorContactRole::class,
 * ],
 * ```
 *
 * A project adds a role of its own without copying a module config (Rule 2) through
 * the extension file `override/z77/module/{module}/src/App/Config/contactRolesConfig.inc.php`,
 * returning the same shape (`ModuleManager::getConfigExtensions()`).
 *
 * Registration is configuration, not a runtime call; a bad entry fails when the
 * registry is built, naming the module (and the file). A key is `[a-z][a-z0-9-]`,
 * 2–32 characters — it travels in the URL of the switch (`toggle-role?role=`). The
 * same key twice with the SAME class is one role; with another class it is a
 * conflict. Order: module order, then declaration order — the order of the switches.
 */
final class ContactRoles
{
    public const CONFIG_KEY  = 'contactRoles';
    public const KEY_PATTERN = '~^[a-z][a-z0-9-]{1,31}$~';

    /** @var array<string, class-string<ContactRoleInterface>> key → class */
    private array $classes = [];

    /** @var array<string, ContactRoleInterface> key → instance, built on first use */
    private array $instances = [];

    /** @param array<string, class-string> $rolesByKey key → role class */
    public function __construct(array $rolesByKey = [])
    {
        $this->addAll($rolesByKey, self::CONFIG_KEY);
    }

    /** The union of every module's `contactRoles` and their extension files. */
    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self();
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);

            foreach ($sources as $origin => $rolesByKey) {
                $registry->addAll($rolesByKey, $origin);
            }
        }

        return $registry;
    }

    /** @return array<string, ContactRoleInterface> key → role, in registration order */
    public function all(): array
    {
        $roles = [];
        foreach (array_keys($this->classes) as $key) {
            $roles[$key] = $this->role($key);
        }

        return $roles;
    }

    /** The role registered under $key, or null — a key from a request is checked here. */
    public function role(string $key): ?ContactRoleInterface
    {
        if (!isset($this->classes[$key])) {
            return null;
        }

        return $this->instances[$key] ??= new ($this->classes[$key])();
    }

    private function addAll(mixed $rolesByKey, string $origin): void
    {
        if (!is_array($rolesByKey) || ($rolesByKey !== [] && array_is_list($rolesByKey))) {
            throw new \RuntimeException("❌ {$origin} must be an array of role key => role class.");
        }
        foreach ($rolesByKey as $key => $class) {
            $this->add((string) $key, $class, $origin);
        }
    }

    private function add(string $key, mixed $class, string $origin): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \RuntimeException("❌ {$origin}: '{$key}' is not a valid role key ([a-z][a-z0-9-], 2–32 characters).");
        }
        if (!is_string($class) || !class_exists($class)) {
            throw new \RuntimeException("❌ {$origin}: role '{$key}' names a non-existent class: " . var_export($class, true));
        }
        if (!is_subclass_of($class, ContactRoleInterface::class)) {
            throw new \RuntimeException("❌ {$origin}: role '{$key}' names {$class}, which does not implement " . ContactRoleInterface::class . '.');
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new \RuntimeException(
                "❌ {$origin}: role '{$key}' names {$class}, which cannot be instantiated without arguments — a role reaches its services through DI."
            );
        }
        if (isset($this->classes[$key]) && $this->classes[$key] !== $class) {
            throw new \RuntimeException("❌ {$origin}: role '{$key}' is already registered by {$this->classes[$key]}.");
        }
        $this->classes[$key] = $class;
    }
}
