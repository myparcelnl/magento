<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * Shortens text for a generated SDK setter, which counts characters (mb_strlen). Str::limit()
 * counts display width, so a name with combining accents passed it and the setter still threw.
 */
class Text
{
    /** The text, or its start plus '...' within $maxCharacters characters. */
    public static function limit(string $text, int $maxCharacters): string
    {
        if (mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxCharacters - 3)) . '...';
    }
}
