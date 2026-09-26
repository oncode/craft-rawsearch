<?php

namespace oncode\rawsearch\helpers;

/**
 * Splits a text into sentences.
 *
 * A sentence ends at a line break or at `.`, `!`, `?`, `…` (plus closing quotes/brackets) followed by whitespace.
 * CJK sentences end at `。`, `！`, `？`.
 * It doesn't end when the next word starts lowercase, after abbreviations (`z.B.`, `Dr.`)
 * and after short numbers (`am 12. Mai`).
 */
class SentenceSplitter
{
    /** Lowercase abbreviations without the dot, a dot after them doesn't end a sentence. */
    public const ABBREVIATIONS = [
        // English
        'mr', 'mrs', 'ms', 'dr', 'prof', 'st', 'jr', 'sr', 'vs', 'etc', 'fig', 'approx', 'dept', 'inc', 'ltd', 'co',
        // German
        'nr', 'bzw', 'ca', 'vgl', 'usw', 'str', 'inkl', 'exkl', 'max', 'min', 'tel', 'abs', 'bspw', 'evtl', 'ggf',
        'sog', 'zb', 'dh', 'hr', 'fr', 'geb', 'jh', 'mio', 'mrd', 'rd', 'bzgl', 'zzgl', 'abt', 'kap', 'lt',
    ];

    /**
     * @return array<array{0: int, 1: int}> Character offsets (start, end) of the sentences, without surrounding whitespace
     */
    public static function split(string $text): array
    {
        $boundaries = [];

        // CJK sentence ends aren't followed by whitespace
        preg_match_all('/([.!?…]+["\'”’“»«)\]]*)(\s+)|([。！？]+[」』）]*)(\s*)|(\s*\n\s*)/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $byte = $match[0][1];
            $after = substr($text, $byte + strlen($match[0][0]));

            // line break: always a boundary
            if (isset($match[5]) && $match[5][1] !== -1) {
                $boundaries[] = [$byte, $byte + strlen($match[0][0])];
                continue;
            }

            // CJK sentence end: always a boundary
            if (isset($match[3]) && $match[3][1] !== -1) {
                if ($after !== '') {
                    $boundaries[] = [$byte + strlen($match[3][0]), $byte + strlen($match[0][0])];
                }
                continue;
            }

            $punctuation = $match[1][0];
            $before = substr($text, 0, $byte);

            // a line break after the punctuation always ends the sentence
            if (!str_contains($match[2][0], "\n") && !self::isSentenceEnd($before, $punctuation, $after)) {
                continue;
            }

            $boundaries[] = [$byte + strlen($punctuation), $byte + strlen($match[0][0])];
        }

        // convert the byte offsets to sentences with character offsets
        $sentences = [];
        $startByte = 0;

        foreach (array_merge($boundaries, [[strlen($text), strlen($text)]]) as [$endByte, $nextStartByte]) {
            $sentence = substr($text, $startByte, $endByte - $startByte);
            $trimmedStart = strlen($sentence) - strlen(ltrim($sentence));
            $trimmed = trim($sentence);

            if ($trimmed !== '') {
                $start = mb_strlen(substr($text, 0, $startByte + $trimmedStart));
                $sentences[] = [$start, $start + mb_strlen($trimmed)];
            }

            $startByte = $nextStartByte;
        }

        return $sentences;
    }

    private static function isSentenceEnd(string $before, string $punctuation, string $after): bool
    {
        // a lowercase word continues the sentence (scripts without case always start a new one)
        if (preg_match('/^["\'„“”‘’«»(\[¿¡]*\p{Ll}/u', $after)) {
            return false;
        }

        if (!str_starts_with($punctuation, '.')) {
            return true;
        }

        // the word in front of the dot
        if (!preg_match('/(\S+)$/u', $before, $word)) {
            return true;
        }

        $word = $word[1];

        // single letters and dotted abbreviations: "z.B.", "e.g.", "A."
        if (preg_match('/^(\p{L}\.)*\p{L}$/u', $word)) {
            return false;
        }

        // ordinals and dates like "12. Mai"
        if (preg_match('/^\d{1,2}$/', $word)) {
            return false;
        }

        $word = mb_strtolower(preg_replace('/^\W+/u', '', $word));

        return !in_array($word, self::ABBREVIATIONS, true);
    }
}
