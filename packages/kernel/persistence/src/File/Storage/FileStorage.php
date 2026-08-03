<?php

namespace Z77\Persistence\File\Storage;

class FileStorage
{
    private string $basePath;

    public function __construct(string $basePath = 'data')
    {
        $this->basePath = ABS_BASE_PATH.'/'.$basePath.'/';

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Loads a data JSON file into an array.
     *
     * Missing file → []. Empty/whitespace file → [] (legitimately empty). A
     * leading UTF-8 BOM is tolerated (stripped before decode) — some external
     * editors/shells add one (see DATA-JSON-001, docs/topics/persistence-file.md).
     *
     * A NON-EMPTY file that fails to parse throws instead of silently returning
     * [] — a corrupt data file (e.g. a BOM'd or truncated navigation.json) must be
     * visible, never blank the entity set behind a silent []. This does NOT catch
     * mojibake double-encoding (that is valid UTF-8 and decodes fine) — that class
     * is a prevention/convention matter, not detectable on read.
     */
    public function load(string $path): array
    {
        $path = trim($path, '/');
        if (!file_exists($this->basePath.$path)) return [];

        $json = file_get_contents($this->basePath.$path);
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);   // tolerate a stray BOM
        if (trim($json) === '') return [];                    // legitimately empty

        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Corrupt data file '{$path}': " . json_last_error_msg()
            );
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Saves data atomically: write to a temp file in the same directory, then
     * rename() onto the target. Readers always see either the old or the new
     * file, never a partially written one (load() does not lock). The temp
     * suffix is NOT .json, so a crash between write and rename leaves a file
     * that list() (glob *.json) never picks up as data.
     *
     * Windows: rename() over an existing target works (MoveFileEx semantics)
     * but fails with a sharing violation while a reader holds the target open
     * — verified on NTFS and the Z: NAS share. Readers hold the handle only
     * for the duration of file_get_contents(), so a short retry loop covers
     * it; if the target stays locked beyond that, save() throws and the old
     * file remains valid (fail-loud, like load() on corruption).
     */
    public function save(string $path, array $data): void
    {
        $path = trim($path, '/');
        $full = $this->basePath.$path;

        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException(
                "Cannot encode data for '{$path}': " . json_last_error_msg()
            );
        }

        $tmp = $full.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write temp file for '{$path}'");
        }

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            if (@rename($tmp, $full)) {
                return;
            }
            usleep($attempt * 5000);   // target briefly held open by a reader
        }

        $error = error_get_last()['message'] ?? 'unknown error';
        @unlink($tmp);
        throw new \RuntimeException("Cannot replace '{$path}': {$error}");
    }

    public function delete(string $path): void
    {
        $path = trim($path, '/');
        $full = $this->basePath.$path;
        if (is_file($full)) {
            unlink($full);
        }
    }

    public function exists(string $path): bool
    {
        return is_file($this->basePath.trim($path, '/'));
    }

    /**
     * Lists all *.json files in a directory (document mode).
     *
     * @return string[] paths relative to the data root, usable with load()/delete()
     */
    public function list(string $dir): array
    {
        $dir  = trim($dir, '/');
        $full = $this->basePath.$dir;
        if (!is_dir($full)) {
            return [];
        }

        $files = glob($full.'/*.json') ?: [];
        sort($files);

        return array_map(fn($f) => $dir.'/'.basename($f), $files);
    }
}
