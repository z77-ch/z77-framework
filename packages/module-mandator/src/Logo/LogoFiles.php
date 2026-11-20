<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Module\Mandator\Entities\Mandator;

/**
 * The READ side of the company logo (2026-11-20): the file one use shows, for the libraries that take
 * a FILE — the PDF writer (`pdf/letterhead`), the mailer's inline image (`Mail\EmailLogo`), the
 * login's inlining (`LoginLogo` → kernel `BrandLogo`).
 *
 * **Only the DMS documents of the logo window** (owner 2026-11-20, «ja los»: the old path fields
 * `logo_path` / `email_logo_path` are no longer read — ADR-039 expand/contract, their DROP is a later
 * release's contract step, mandator.md):
 *
 *   | use     | DMS document, in this order                              |
 *   |---------|----------------------------------------------------------|
 *   | print   | `print_logo_document_id`                                 |
 *   | email   | `email_logo_document_id`                                 |
 *   | login   | `login_logo_document_id`, then `email_logo_document_id`  |
 *   | web     | — a page links its public address, {@see WebLogo}        |
 *
 * The login falls back to the E-MAIL document (owner 2026-11-20): a project whose «Anmeldung» row
 * took no upload («eigenes Design») or that uploaded only for e-mail shows the mails' logo there. The
 * e-mail variant is made for the same size (density 2), so kernel `BrandLogo`'s 96 KB limit holds.
 *
 * A stored document that cannot be read (in the Drive's trash, moved away, module-dms gone, bytes
 * altered) is «no logo» — never an exception.
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
     * derived high-density variant) — or null: no document stored or none readable.
     *
     * @return array{path: string, density: int}|null
     */
    public static function of(Mandator $mandator, string $use): ?array
    {
        if (LogoDrive::available()) {
            foreach (self::documentUses($use) as $documentUse) {
                $documentId = $mandator->logoDocument($documentUse);
                $file       = $documentId !== null ? self::materialized($documentUse, $documentId) : null;
                if ($file !== null) {
                    return ['path' => $file, 'density' => LogoImage::DENSITY[$documentUse] ?? 1];
                }
            }
        }

        return null;
    }

    /** Whether `$use` has a logo at all — a stored document id counts without reading it (the setup step's cheap question). */
    public static function present(Mandator $mandator, string $use): bool
    {
        foreach (self::documentUses($use) as $documentUse) {
            if ($mandator->logoDocument($documentUse) !== null) {
                return true;
            }
        }

        return false;
    }

    /** The uses whose DMS document `$use` shows, in order — the login falls back to the e-mail's (see the class docblock). */
    private static function documentUses(string $use): array
    {
        return $use === LogoUses::LOGIN ? [LogoUses::LOGIN, LogoUses::EMAIL] : [$use];
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
