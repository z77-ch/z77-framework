<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Core\DI;

/**
 * The four uses of the company logo (owner decisions 2026-11-20, the logo set-up window): what the
 * window asks for, what the setup step counts, and which uses this installation shows at all.
 *
 *   - `print` — «Belege (PDF)»: the letterhead of every PDF (`pdf/letterhead`);
 *   - `email` — «E-Mail»: the inline image `logo` of every template mail (`Mail\EmailLogo`);
 *   - `login` — «Anmeldung»: the brand mark of the backend login screens (`LoginLogo`, `brandLogo`);
 *   - `web`   — «Website»: the brand mark of the default frontend header and footer (`WebLogo`,
 *     `siteLogo`) — only where module-frontend is registered.
 *
 * **«Eigenes Design»** — a project that overrides the markup keeps it (CE, Rule 1): the login and the
 * website show the mandator's logo only through the shipped `Z77\Shared` `partials/brandMark`; the
 * website also only through module-frontend's `partials/header`. An override of either is detected
 * by the FileFinder's lookup order (override paths first, the package last): a file of that name in
 * any path BEFORE the package's is an override. Such a use is shown as «eigenes Design» in the
 * window, takes no upload and does not count for the setup step. What the project's own partial
 * shows instead is its business — it may still render `brandMark` with `siteLogo` (help text).
 */
final class LogoUses
{
    public const PRINT = 'print';
    public const EMAIL = 'email';
    public const LOGIN = 'login';
    public const WEB   = 'web';

    /** Every use, in the window's order — the same list as `Mandator::LOGO_USES`. */
    public const ALL = [self::PRINT, self::EMAIL, self::LOGIN, self::WEB];

    /** The window's row labels. */
    public const LABELS = [
        self::PRINT => 'Belege (PDF)',
        self::EMAIL => 'E-Mail',
        self::LOGIN => 'Anmeldung',
        self::WEB   => 'Website',
    ];

    /** The words of the setup step («Firmenlogo für Belege und E-Mails hinterlegen»). */
    public const STEP_WORDS = [
        self::PRINT => 'Belege',
        self::EMAIL => 'E-Mails',
        self::LOGIN => 'Anmeldung',
        self::WEB   => 'Website',
    ];

    /** The uses this installation shows: `web` only with module-frontend registered. */
    public static function shown(): array
    {
        return array_values(array_filter(
            self::ALL,
            static fn(string $use): bool => $use !== self::WEB || DI::getModuleManager()->getModuleConfig('frontend') !== null
        ));
    }

    /** The shown uses the mandator's logo actually reaches — `shown()` without the «eigenes Design» ones. */
    public static function served(): array
    {
        return array_values(array_filter(self::shown(), static fn(string $use): bool => !self::ownDesign($use)));
    }

    /** Whether the project renders this use with its OWN markup (an override, see the class docblock). */
    public static function ownDesign(string $use): bool
    {
        return match ($use) {
            self::LOGIN => self::overridden('partials/brandMark.tpl.php', 'Z77\\Shared'),
            self::WEB   => self::overridden('partials/brandMark.tpl.php', 'Z77\\Shared')
                || self::overridden('partials/header.tpl.php', 'Z77\\Module\\Frontend'),
            default     => false,
        };
    }

    /** A template of that name in a source path before the package's own (the last one). Never throws. */
    private static function overridden(string $template, string $namespace): bool
    {
        try {
            $finder = DI::getFileFinder();
            $paths  = $finder->getBasePaths($namespace, 'sourcePaths');
            $tplDir = $finder->getTplDir();
            foreach (array_slice($paths, 0, -1) as $path) {
                if (is_file(rtrim((string) $path, '/') . '/' . $tplDir . '/' . $template)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            error_log('mandator logo: override probe of ' . $template . ' failed: ' . $e->getMessage());
        }

        return false;
    }
}
