<?php

namespace oncode\rawsearch\helpers;

use CraftCms\Cms\Support\Str;

/**
 * String normalization used for both indexing and searching.
 *
 * Index and query have to go through exactly the same normalization,
 * otherwise words won't match.
 */
class StringHelper
{
    private static ?array $asciiMap = null;

    /**
     * Normalizes a string: strips html, punctuation, lowercases and replaces diacritics.
     *
     * @param string[] $ignore Words to strip out
     */
    public static function normalize(string $str, array $ignore = []): string
    {
        $str = static::stripHtml($str);
        $str = static::removePunctuation($str);
        $str = mb_strtolower($str);
        $str = static::replaceDiacritics($str);

        foreach ($ignore as $word) {
            $word = static::normalize((string)$word);

            if ($word !== '') {
                $str = preg_replace('/(?<!\S)' . preg_quote($word, '/') . '(?!\S)/u', ' ', $str);
            }
        }

        return trim(static::collapseWhitespace($str));
    }

    /**
     * Replaces every punctuation and symbol character with a single space.
     * The character count stays the same, which the snippet extractor relies on.
     */
    public static function removePunctuation(string $str): string
    {
        return preg_replace('/[\p{P}\p{S}]/u', ' ', $str);
    }

    /**
     * Removes html tags and decodes entities.
     * Block elements and line breaks become new lines, they separate sentences.
     */
    public static function stripHtml(string $str): string
    {
        $str = preg_replace('/<(?:br|hr)\b[^>]*>|<\/(?:p|div|li|dt|dd|h[1-6]|tr|td|th|blockquote|pre|figcaption|section|article)\s*>/i', "\n", $str);
        // inline elements don't separate words ("<b>word</b>." stays "word.")
        $str = preg_replace('/<\/?(?:a|abbr|b|bdi|bdo|cite|code|data|del|dfn|em|font|i|ins|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var)\b[^>]*>/i', '', $str);
        // prevent text from sticking together when other tags are removed
        $str = preg_replace('/<[^>]*>/', ' ', $str);
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // non-breaking spaces become regular spaces
        return str_replace("\u{00A0}", ' ', $str);
    }

    public static function collapseWhitespace(string $str): string
    {
        return preg_replace('/\s+/u', ' ', $str);
    }

    /**
     * Collapses whitespace but keeps (single) line breaks.
     */
    public static function collapseWhitespaceKeepLines(string $str): string
    {
        $str = preg_replace('/[^\S\n]+/u', ' ', $str);

        return preg_replace('/ *\n[\s]*/u', "\n", $str);
    }

    public static function replaceDiacritics(string $str): string
    {
        if (self::$asciiMap === null) {
            // drop mappings that would add whitespace, the extractor needs stable word boundaries
            self::$asciiMap = array_filter(
                Str::asciiCharMap(true, null),
                fn($v, $k) => !preg_match('/\s/u', $v . $k),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return strtr($str, self::$asciiMap);
    }

    /**
     * Encodes special characters for html output.
     */
    public static function encodeHtml(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escapes characters with a special meaning in LIKE conditions.
     */
    public static function escapeLike(string $str): string
    {
        return strtr($str, ['\\' => '\\\\', '%' => '\%', '_' => '\_']);
    }
}
