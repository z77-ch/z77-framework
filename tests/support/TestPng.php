<?php

/**
 * A real PNG of a given pixel size, written without GD (2026-10-23, the e-mail logo harness
 * checks): signature, IHDR (8-bit RGB), one zlib-compressed IDAT of red pixels, IEND —
 * enough for `getimagesize()` and any mail client. Harness support only.
 */
final class TestPng
{
    public static function bytes(int $width, int $height): string
    {
        $chunk = static fn(string $type, string $data): string
            => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $rows  = str_repeat("\x00" . str_repeat("\xCC\x22\x22", $width), $height);

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($rows))
            . $chunk('IEND', '');
    }

    public static function write(string $path, int $width, int $height): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, self::bytes($width, $height));
    }
}
