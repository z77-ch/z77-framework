<?php

namespace Z77\Tests\Support;

use Z77\Core\DI;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\I18n;
use Z77\Core\Services\Translator;

/**
 * A real {@see Translator} for a harness without a container (ADR-056): mail subjects and
 * templates call `t()` now, so a harness that builds a mail needs one. Registers three
 * services in the CURRENT DI instance — call it again after `DI::getInstance(true)`:
 *
 *   I18n        de default, de/fr/en offered (or what is passed)
 *   FileFinder  answers the package roots given, so the module dictionaries under
 *               `<root>/res/i18n/` are read exactly as in production
 *   Translator  the production class
 *
 * No runtime layer unless the harness writes `ABS_BASE_PATH/data/framework/i18n/<lang>.json`.
 * No request: the current language is the default, unless an inLanguage() scope says otherwise.
 */
final class TestTranslator
{
    /**
     * @param list<string> $roots      package roots, e.g. `dirname(__DIR__) . '/packages/module-member'`
     * @param list<string> $languages  offered languages, the first is the default
     */
    public static function install(array $roots, array $languages = ['de', 'fr', 'en']): void
    {
        $i18n = new class($languages) extends I18n {
            /** @param list<string> $languages */
            public function __construct(private array $languages)
            {
            }

            public function getDefaultLanguage(): string
            {
                return $this->languages[0];
            }

            public function getLanguages(): array
            {
                return $this->languages;
            }
        };

        $finder = new class($roots) extends FileFinder {
            /** @param list<string> $roots */
            public function __construct(private array $roots)
            {
            }

            public function getAllNamespaces(): array
            {
                $namespaces = [];
                foreach ($this->roots as $i => $root) {
                    $namespaces['Test\\Ns' . $i . '\\'] = ['sourcePaths' => [$root]];
                }
                return $namespaces;
            }

            public function getBasePaths(string $ns, string $resourceType): array
            {
                return $this->getAllNamespaces()[$ns][$resourceType] ?? [];
            }
        };

        DI::getInstance()
            ->set('I18n', $i18n, true)
            ->set('FileFinder', $finder, true)
            ->set('Translator', new Translator(), true);
    }

    /** The package roots of the given monorepo packages (`module-member`, `kernel/shared` …). */
    public static function packages(string ...$names): array
    {
        $base = dirname(__DIR__, 2) . '/packages/';

        return array_map(static fn(string $n): string => $base . $n, $names);
    }
}
