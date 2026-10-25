<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * UTF-8 helpers counting characters, not bytes. Uses ext-mbstring when present and a
 * PCRE fallback otherwise, so the package needs no extension beyond curl and json.
 */
final class Text
{
    public static function length(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        return (int) preg_match_all('/./us', $s);
    }

    public static function cut(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (strlen($s) <= $max) {
            return $s; // bytes <= max implies characters <= max
        }
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        preg_match_all('/./us', $s, $m);
        return implode('', array_slice($m[0], 0, $max));
    }
}
