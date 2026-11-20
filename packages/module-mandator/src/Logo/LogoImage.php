<?php

namespace Z77\Module\Mandator\Logo;

/**
 * The server derives the logo's variants itself (owner decision 2026-11-20: «nobody has to know about
 * resolution») — ext-gd, a required extension of the kernel. Pure: bytes in, bytes out, no I/O.
 *
 * **What is accepted** ({@see decode()}): PNG, JPEG or GIF judged by the CONTENT — the header
 * (`getimagesizefromstring`) names one of the three and gd really decodes it. SVG is refused (mail
 * clients do not show it, and it is a script carrier), and so is everything else whatever its name.
 * At most {@see MAX_UPLOAD_BYTES} and {@see MAX_PIXELS} (a 5 MB PNG of one colour can be 50
 * megapixels; gd holds 4 bytes per pixel) — checked from the header BEFORE gd decodes.
 *
 * **What comes out** ({@see variant()}): always a RE-ENCODED image — gd draws the pixels into a new
 * canvas and writes a new file, so metadata (EXIF, comments, a payload appended to the file) never
 * survives, and the file is one the PDF writer reads (FPDF refuses an interlaced or 16-bit PNG).
 * PNG and GIF become PNG with their transparency, JPEG stays JPEG. Never enlarged.
 *
 *   - `print`  — the uploaded resolution, capped at {@see PRINT_MAX} px on the longer side (the PDF
 *     scales it; a larger file only makes every PDF heavier);
 *   - `email` / `login` — 2× of the display size: at most {@see SCREEN_BOX} px, and at most
 *     {@see SCREEN_MAX_BYTES} (the login inlines it — kernel `BrandLogo::MAX_BYTES`); the readers
 *     declare density 2, so a mail and the login show it at half its pixel size;
 *   - `web`    — 2× of the frontend header's logo box: at most {@see WEB_BOX} px and
 *     {@see WEB_MAX_BYTES}; shown at half its pixel size.
 *
 * Above the byte limit a variant is written smaller step by step: a JPEG with less quality, a PNG
 * with a 256-colour palette (transparency kept), then both scaled down by a quarter each round.
 */
final class LogoImage
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS       = 16_000_000;

    /** The longer side of the print variant. */
    public const PRINT_MAX = 2000;

    /** [width, height] of the e-mail / login variant — 2× of about 100 × 60 display pixels. */
    public const SCREEN_BOX = [200, 120];

    /** The login inlines the file into the page — kernel `Site\BrandLogo::MAX_BYTES` (96 KB). */
    public const SCREEN_MAX_BYTES = 96 * 1024;

    /** [width, height] of the website variant — 2× of 240 × 80 display pixels. */
    public const WEB_BOX = [480, 160];

    public const WEB_MAX_BYTES = 256 * 1024;

    /** How many file pixels make one display pixel, per use (the readers pass it to `InlineImage`). */
    public const DENSITY = [LogoUses::PRINT => 1, LogoUses::EMAIL => 2, LogoUses::LOGIN => 2, LogoUses::WEB => 2];

    private function __construct(
        private readonly \GdImage $image,
        private readonly bool $jpeg,
    ) {
    }

    /**
     * The uploaded bytes as an image, or a German sentence why not.
     *
     * @return self|string
     */
    public static function decode(string $bytes): self|string
    {
        if ($bytes === '') {
            return 'Die Datei ist leer.';
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            return 'Die Datei ist zu gross (höchstens ' . intdiv(self::MAX_UPLOAD_BYTES, 1024 * 1024) . ' MB).';
        }
        $info = @getimagesizefromstring($bytes);
        $type = is_array($info) ? ($info[2] ?? null) : null;
        if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) {
            return 'Bitte eine PNG-, JPG- oder GIF-Datei wählen (SVG und andere Formate werden nicht angenommen).';
        }
        $width  = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1) {
            return 'Das Bild hat keine lesbare Grösse.';
        }
        if ($width * $height > self::MAX_PIXELS) {
            return 'Das Bild ist zu gross (höchstens 4000 × 4000 Pixel).';
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            return 'Das Bild lässt sich nicht lesen — bitte die Datei nochmals als PNG oder JPG speichern.';
        }
        // A palette image (GIF, an 8-bit PNG) becomes true colour; its transparent index becomes alpha.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagesavealpha($image, true);

        return new self($image, $type === IMAGETYPE_JPEG);
    }

    /** The extension of the variants: `jpg` for a JPEG upload, `png` for everything else. */
    public function ext(): string
    {
        return $this->jpeg ? 'jpg' : 'png';
    }

    public function mime(): string
    {
        return $this->jpeg ? 'image/jpeg' : 'image/png';
    }

    /**
     * The variant of one use — re-encoded bytes (see the class docblock).
     *
     * @throws \RuntimeException when gd cannot write it (should not happen after decode())
     */
    public function variant(string $use): string
    {
        [$boxW, $boxH, $maxBytes] = match ($use) {
            LogoUses::PRINT => [self::PRINT_MAX, self::PRINT_MAX, PHP_INT_MAX],
            LogoUses::EMAIL, LogoUses::LOGIN => [self::SCREEN_BOX[0], self::SCREEN_BOX[1], self::SCREEN_MAX_BYTES],
            LogoUses::WEB   => [self::WEB_BOX[0], self::WEB_BOX[1], self::WEB_MAX_BYTES],
            default         => throw new \InvalidArgumentException("Unknown logo use '{$use}'."),
        };
        $srcW  = imagesx($this->image);
        $srcH  = imagesy($this->image);
        $scale = min(1.0, $boxW / $srcW, $boxH / $srcH);

        for ($round = 0; $round < 8; $round++) {
            $w      = max(1, (int) round($srcW * $scale));
            $h      = max(1, (int) round($srcH * $scale));
            $canvas = $this->scaled($w, $h);
            foreach ($this->encodings() as $encode) {
                $bytes = $encode($canvas);
                if (strlen($bytes) <= $maxBytes) {
                    return $bytes;
                }
            }
            $scale *= 0.75;   // still too large: a quarter smaller, all encodings again
        }

        throw new \RuntimeException("LogoImage: the {$use} variant does not fit into {$maxBytes} bytes.");
    }

    /** A fresh true-colour canvas of $w × $h with the image drawn into it, transparency kept. */
    private function scaled(int $w, int $h): \GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, $this->jpeg ? 0 : 127));
        imagecopyresampled($canvas, $this->image, 0, 0, 0, 0, $w, $h, imagesx($this->image), imagesy($this->image));

        return $canvas;
    }

    /** @return list<callable(\GdImage): string> from the best to the smallest */
    private function encodings(): array
    {
        $capture = static function (callable $write): string {
            ob_start();
            try {
                if ($write() !== true) {
                    throw new \RuntimeException('LogoImage: gd could not encode the variant.');
                }

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        if ($this->jpeg) {
            return array_map(
                static fn(int $quality) => static fn(\GdImage $img): string => $capture(static function () use ($img, $quality) {
                    imageinterlace($img, false);   // baseline: FPDF reads progressive JPEGs too, mail clients all do — kept simple

                    return imagejpeg($img, null, $quality);
                }),
                [90, 80, 70, 60]
            );
        }

        return [
            static fn(\GdImage $img): string => $capture(static function () use ($img) {
                imageinterlace($img, false);   // FPDF refuses an interlaced PNG

                return imagepng($img, null, 9);
            }),
            static fn(\GdImage $img): string => $capture(static function () use ($img) {
                $palette = imagecreatetruecolor(imagesx($img), imagesy($img));
                imagealphablending($palette, false);
                imagesavealpha($palette, true);
                imagecopy($palette, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                imagetruecolortopalette($palette, true, 256);
                imageinterlace($palette, false);

                return imagepng($palette, null, 9);
            }),
        ];
    }
}
