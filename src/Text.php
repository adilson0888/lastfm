<?php

declare(strict_types=1);

/**
 * Small presentation/server helpers used by the endpoints.
 */
final class Text
{
    /**
     * Strip accents so names can be embedded safely in a custom URL scheme
     * (e.g. tasker://Iron-Maiden). Replaces the removed-in-PHP-8.2 utf8_decode
     * approach with an mbstring-based transliteration.
     */
    public static function stripAccents(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            return $transliterated;
        }

        // Fallback: no transliteration available, keep the original characters
        // that are already ASCII and drop the rest.
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
    }

    /**
     * Build the deep link used by the Tasker integration / music apps.
     */
    public static function artistDeepLink(string $scheme, string $name): string
    {
        return $scheme . '://' . self::stripAccents($name);
    }
}
