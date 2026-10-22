<?php

namespace Z77\Module\Mailing\Attachments;

use Z77\Core\Services\ModuleManager;
use Z77\Shared\Mail\Attachment;

/**
 * The attachment-provider registry of the outbox (ADR-053) — the pattern of
 * the dashboard registry and `openWorkChecks`: a module DECLARES under its
 * config key `mailAttachments` which {@see AttachmentProvider} class answers
 * which reference TYPE; the outbox collects the union across all modules.
 * Explicit, one line per type, and a bad entry fails at the first use naming
 * the module and the file.
 *
 * ```php
 * // module-debtor: debtorConfig.inc.php (step 3 of the mail plan)
 * 'mailAttachments' => ['invoice-pdf' => InvoicePdfAttachment::class],
 * ```
 *
 * Two additive sources per module: the `mailAttachments` key of the module
 * config and every extension file `App/Config/mailAttachmentsConfig.inc.php`
 * (`ModuleManager::getConfigExtensions()`), same shape — how a project adds a
 * type of its own without copying a module config (Rule 2). A type declared
 * by two different classes is refused: which provider would win is not a
 * question to leave to the boot order.
 */
final class AttachmentProviders
{
    public const CONFIG_KEY = 'mailAttachments';

    /** @var array<string, class-string<AttachmentProvider>> type => class */
    private array $classes = [];

    /** @var array<string, string> type => origin, for the double-declaration message */
    private array $origins = [];

    /** @var array<string, AttachmentProvider> */
    private array $instances = [];

    /** @param array<string, class-string> $classesByType type => provider class (tests, or a caller that already knows them) */
    public function __construct(array $classesByType = [])
    {
        $this->add($classesByType, self::CONFIG_KEY);
    }

    /** The union of every module's `mailAttachments` declaration and extension files. */
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
            foreach ($sources as $origin => $classesByType) {
                $registry->add($classesByType, $origin);
            }
        }

        return $registry;
    }

    /** @return list<string> the registered types */
    public function types(): array
    {
        return array_keys($this->classes);
    }

    public function has(string $type): bool
    {
        return isset($this->classes[$type]);
    }

    /**
     * Queue-time check of one reference: the form, a registered type, and the
     * provider's own `accepts()`. Returns the German reason for a refusal, null
     * when it is fine.
     */
    public function refusal(string $reference): ?string
    {
        try {
            $ref = AttachmentReference::parse($reference);
        } catch (\InvalidArgumentException) {
            return "Anhang «{$reference}»: keine gültige Referenz (Form typ:id).";
        }
        if (!$this->has($ref->type)) {
            return "Anhang «{$reference}»: kein Modul liefert Anhänge vom Typ «{$ref->type}».";
        }
        if (!$this->provider($ref->type)->accepts($ref->id)) {
            return "Anhang «{$reference}»: nicht gefunden.";
        }

        return null;
    }

    /**
     * Send-time resolution. Throws whatever the provider throws, and a
     * RuntimeException for a type that is no longer registered (the module was
     * removed while mails waited) — both become a retry of the mail.
     */
    public function resolve(string $reference): Attachment
    {
        $ref = AttachmentReference::parse($reference);
        if (!$this->has($ref->type)) {
            throw new \RuntimeException("No module provides mail attachments of type '{$ref->type}' (reference '{$reference}').");
        }
        $attachment = $this->provider($ref->type)->resolve($ref->id);
        if (!$attachment instanceof Attachment) {
            throw new \UnexpectedValueException($this->classes[$ref->type] . '::resolve() must return ' . Attachment::class . '.');
        }

        return $attachment;
    }

    private function provider(string $type): AttachmentProvider
    {
        return $this->instances[$type] ??= new ($this->classes[$type])();
    }

    /** Everything that would fail at the first use fails here: the shape, the class, no double type. */
    private function add(mixed $classesByType, string $origin): void
    {
        if (!is_array($classesByType) || ($classesByType !== [] && array_is_list($classesByType))) {
            throw new \RuntimeException("❌ {$origin} must be an array of type => " . AttachmentProvider::class . ' class.');
        }
        foreach ($classesByType as $type => $class) {
            if (!is_string($type) || preg_match(AttachmentReference::TYPE_PATTERN, $type) !== 1) {
                throw new \RuntimeException("❌ {$origin}: attachment type " . var_export($type, true) . ' must be lower-case kebab (e.g. invoice-pdf).');
            }
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin}: type '{$type}' names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, AttachmentProvider::class)) {
                throw new \RuntimeException("❌ {$origin}: type '{$type}' names {$class}, which does not implement " . AttachmentProvider::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin}: type '{$type}' names {$class}, which cannot be instantiated without arguments — a provider reaches its services through DI.");
            }
            if (isset($this->classes[$type]) && $this->classes[$type] !== $class) {
                throw new \RuntimeException("❌ {$origin}: attachment type '{$type}' is already provided by {$this->classes[$type]} ({$this->origins[$type]}).");
            }
            $this->classes[$type] = $class;
            $this->origins[$type] ??= $origin;
        }
    }
}
