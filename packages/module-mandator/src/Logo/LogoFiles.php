<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Shared\Files\LocalFiles;

/**
 * The READ side of the company logo (2026-11-20): the file one use shows, for the libraries that take
 * a FILE — the PDF writer (`pdf/letterhead`), the mailer's inline image (`Mail\EmailLogo`), the
 * login's inlining (`LoginLogo` → kernel `BrandLogo`).
 *
 * **Read order, everywhere: the DMS document first, else the old path field** (owner decision):
 *
 *   | use     | DMS document (logo window)  | else the path field (kept, z77.ch uses it) |
 *   |---------|-----------------------------|--------------------------------------------|
 *   | print   | `print_logo_document_id`    | `logo_path`                                |
 *   | email   | `email_logo_document_id`    | `email_logo_path`                          |
 *   | login   | `login_logo_document_id`    | `email_logo_path` (the login logo of LOGIN-LOGO-001) |
 *   | web     | — a page links its public address, {@see WebLogo} | none                         |
 *
 * A stored document that cannot be read (in the Drive's trash, moved away, module-dms gone, bytes
 * altered) falls back to the path field — and to «no logo» after it; never an exception.
 *
 * The DMS hands a module BYTES (`ModuleDrive::read()`), the libraries want a file: the bytes are
 * written once to `var/cache/mandator-logo/<sha256>/logo.<ext>` — release-local and disposable
 * (ADR-034/035), named by their content, so a changed logo is a new file and nothing goes stale; an
 * existing file is used only when its own hash still matches. The basename `logo.<ext>` is what a
 * mail shows as the inline part's name. Per process the answer is remembered per document.
 */
final class LogoFiles
{
    /** The cache directory below `var/cache`. */
    public const CACHE_DIR = 'mandator-logo';

    /** @var array<int, string|null> document id → materialized file */
    private static array $memo = [];

    /**
     * The file of `$use` and the density its pixels are made for (1 = display size, 2 = the
     * derived high-density variant) — or null: nothing stored or nothing readable.
     *
     * @return array{path: string, density: int}|null
     */
    public static function of(Mandator $mandator, string $use): ?array
    {
        $documentId = $mandator->logoDocument($use);
        if ($documentId !== null && LogoDrive::available()) {
            $file = self::materialized($use, $documentId);
            if ($file !== null) {
                return ['path' => $file, 'density' => LogoImage::DENSITY[$use] ?? 1];
            }
        }
        $stored = match ($use) {
            LogoUses::PRINT                  => $mandator->getLogoPath(),
            LogoUses::EMAIL, LogoUses::LOGIN => $mandator->getEmailLogoPath(),
            default                          => '',
        };
        // A project file or a Drive `/media` path (kernel LocalFiles, 2026-10-23).
        $file = trim($stored) !== '' ? LocalFiles::path(trim($stored)) : null;

        return $file !== null ? ['path' => $file, 'density' => 1] : null;
    }

    /** Whether `$use` has a logo at all — a stored document id counts without reading it (the setup step's cheap question). */
    public static function present(Mandator $mandator, string $use): bool
    {
        if ($mandator->logoDocument($use) !== null) {
            return true;
        }
        $stored = match ($use) {
            LogoUses::PRINT                  => $mandator->getLogoPath(),
            LogoUses::EMAIL, LogoUses::LOGIN => $mandator->getEmailLogoPath(),
            default                          => '',
        };

        return trim($stored) !== '' && LocalFiles::path(trim($stored)) !== null;
    }

    private static function materialized(string $use, int $documentId): ?string
    {
        if (array_key_exists($documentId, self::$memo)) {
            return self::$memo[$documentId];
        }
        $file = null;
        try {
            $bytes = (new LogoDrive())->read($use, $documentId);
            $file  = $bytes !== null ? self::write($bytes) : null;
        } catch (\Throwable $e) {
            error_log('mandator logo: document ' . $documentId . ' (' . $use . ') not readable: ' . get_class($e) . ': ' . $e->getMessage());
        }

        return self::$memo[$documentId] = $file;
    }

    /** The bytes as a file under var/cache, named by their hash — null when it cannot be written. */
    private static function write(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $ext  = match (is_array($info) ? ($info[2] ?? null) : null) {
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF  => 'gif',
            default        => null,
        };
        if ($ext === null) {
            return null;
        }
        $base = defined('ABS_VAR_PATH') ? ABS_VAR_PATH : (defined('ABS_BASE_PATH') ? ABS_BASE_PATH . '/var' : null);
        if ($base === null) {
            return null;
        }
        $hash = hash('sha256', $bytes);
        $dir  = $base . '/cache/' . self::CACHE_DIR . '/' . $hash;
        $file = $dir . '/logo.' . $ext;
        if (is_file($file) && hash_file('sha256', $file) === $hash) {
            return $file;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('mandator logo: cannot create ' . $dir);

            return null;
        }
        $tmp = $dir . '/.logo-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $file)) {
            @unlink($tmp);
            error_log('mandator logo: cannot write ' . $file);

            return null;
        }

        return $file;
    }
}
