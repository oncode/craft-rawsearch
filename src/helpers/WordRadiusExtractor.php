<?php

namespace oncode\rawsearch\helpers;

use Illuminate\Support\Facades\Log;

/**
 * Extracts the found words and their surrounding words out of a text.
 *
 * How it works: the text is normalized the same way as the query, but in a way that keeps
 * the number of whitespaces identical. Matches are searched in the normalized text and
 * mapped back to the original text via the index of the whitespace in front of them.
 */
class WordRadiusExtractor
{
    public const MODE_EXACT = 1;
    public const MODE_WORD_START = 2;
    public const MODE_WORD_CONTENT = 3;

    protected string $text;
    protected int $textLength = 0;
    private string $textNormalized = '';

    /** @var string[] */
    protected array $phraseWords;

    /** @var int[] Character offsets of all whitespaces in the text */
    protected array $whitespaces = [];

    /** @var array<int,int> Byte offset of whitespace in normalized text => whitespace index */
    protected array $normalizedWhitespaceIndexes = [];
    protected int $nrOfWhitespaces = 0;

    /** @var int[] Byte offsets (in normalized text) of the whitespace in front of each match */
    protected array $matches = [];
    protected int $matchesCount = 0;

    protected int $wordsFound = 0;
    protected array $extracts = [];

    // state of the extract that is currently built
    private int $m = 0;
    private string $extract = '';
    private array $extractParts = [];
    private array $words = [];
    private bool $isAtStart = false;
    private bool $isAtEnd = false;
    private int $startWhitespaceIndex = 0;
    private int $endWhitespaceIndex = 0;
    private int $startWhitespaceIndexRadius = 0;
    private int $endWhitespaceIndexRadius = 0;
    private int $endPosWord = 0;
    private int $endPosRadius = 0;
    private int $nextMatchIndex = 0;
    private ?int $nextMatchStartWhitespaceIndex = null;
    private bool $hasWordInRadius = false;

    /**
     * @param string $text Text to search and extract
     * @param string $phrase Normalized phrase (multiple words separated by whitespace)
     * @param string $wrap Wrap code for found words, e.g. `<mark>{phrase}</mark>`
     * @param int $wordMode 1 = exact word, 2 = word start, 3 = word content
     * @param int $wordRadius Number of words shown around the found word
     * @param int $limit Max number of extracts
     * @param bool $isHtml Whether the text is html (tags are stripped and entities decoded)
     */
    public function __construct(
        string $text,
        string $phrase,
        protected string $wrap = '',
        protected int $wordMode = self::MODE_WORD_START,
        protected int $wordRadius = 4,
        protected int $limit = 10,
        protected bool $isHtml = true,
    ) {
        $this->text = $text;
        $this->phraseWords = array_values(array_filter(explode(' ', $phrase), fn($w) => $w !== ''));
    }

    public function getWordsFound(): int
    {
        return $this->wordsFound;
    }

    public function getExtracts(): array
    {
        return $this->extracts;
    }

    public function extract(): void
    {
        $this->wordsFound = 0;
        $this->extracts = [];

        if (empty($this->phraseWords) || $this->limit < 1) {
            return;
        }

        if (!$this->prepare()) {
            return;
        }

        $this->prepareMatches();
        $this->buildExtracts();
    }

    protected function prepare(): bool
    {
        $this->text = ' ' . ($this->isHtml ? IndexHelper::prepareText($this->text) : $this->text) . ' ';
        $this->textLength = mb_strlen($this->text);

        // punctuation becomes whitespace, which keeps the character count of the original text
        $textWhitespaced = StringHelper::removePunctuation($this->text);

        // lowercase and replace diacritics to be able to match the normalized query words
        $this->textNormalized = StringHelper::replaceDiacritics(mb_strtolower($textWhitespaced));

        // character offsets of the whitespaces in the original text
        preg_match_all('/\s/u', $textWhitespaced, $matches, PREG_OFFSET_CAPTURE);
        $prevByte = 0;
        $charPos = 0;

        foreach ($matches[0] as [, $byte]) {
            $charPos += mb_strlen(substr($textWhitespaced, $prevByte, $byte - $prevByte));
            $prevByte = $byte;
            $this->whitespaces[] = $charPos;
        }

        preg_match_all('/\s/u', $this->textNormalized, $matches, PREG_OFFSET_CAPTURE);
        $this->normalizedWhitespaceIndexes = array_flip(array_map(fn($match) => $match[1], $matches[0]));
        $this->nrOfWhitespaces = count($this->whitespaces);

        if ($this->nrOfWhitespaces !== count($this->normalizedWhitespaceIndexes)) {
            Log::warning('RawSearch: amount of whitespaces differs, no snippets extracted for: ' . $this->text);
            return false;
        }

        return true;
    }

    protected function prepareMatches(): void
    {
        $this->matches = [];

        foreach ($this->phraseWords as $phraseWord) {
            if (preg_match_all($this->getRegex($phraseWord), $this->textNormalized, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [, $byte]) {
                    $this->matches[] = $byte;
                }
            }
        }

        $this->matches = array_values(array_unique($this->matches));
        sort($this->matches);
        $this->matchesCount = count($this->matches);
    }

    private function getRegex(string $phraseWord): string
    {
        $word = preg_quote($phraseWord, '/');

        return match ($this->wordMode) {
            // lookahead, so the end whitespace can be the start whitespace of the next match
            self::MODE_EXACT => '/\s' . $word . '(?=\s)/u',
            self::MODE_WORD_CONTENT => '/\s\w*' . $word . '\w*/u',
            default => '/\s' . $word . '\w*/u',
        };
    }

    private function addPart(string $part): void
    {
        // line breaks (paragraphs) are kept in the text for the sentence detection, extracts are one line
        $part = strtr($part, ["\n" => ' ', "\r" => ' ']);
        $this->extractParts[] = $part;
        $this->extract .= StringHelper::encodeHtml($part);
    }

    private function addWord(string $word): void
    {
        $this->words[] = $word;
        $this->wordsFound++;
        $this->extractParts[] = $word;
        $this->extract .= $this->wrapWord($word);
    }

    /**
     * Returns the escaped word wrapped with the wrap code.
     */
    protected function wrapWord(string $word): string
    {
        $encoded = StringHelper::encodeHtml($word);

        return $this->wrap !== '' ? str_replace('{phrase}', $encoded, $this->wrap) : $encoded;
    }

    /**
     * Returns the character offsets (start, end) of all found words in the text, sorted.
     *
     * @return array<array{0: int, 1: int}>
     */
    protected function matchedWordRanges(): array
    {
        $ranges = [];

        foreach ($this->matches as $match) {
            $index = $this->normalizedWhitespaceIndexes[$match];
            $ranges[] = [$this->whitespaces[$index] + 1, $this->whitespaces[$index + 1]];
        }

        return $ranges;
    }

    /**
     * Returns the index of the whitespace where the radius in front of a word starts and whether that is the start of the text.
     *
     * @return array{0: int, 1: bool}
     */
    private function startRadius(int $startWhitespaceIndex): array
    {
        $index = $startWhitespaceIndex;
        $isAtStart = false;

        for ($r = $this->wordRadius; !$isAtStart && $r > 0; $r--) {
            $index--;

            if ($index <= 0) {
                $isAtStart = true;
                $index = 0;
            } elseif ($this->whitespaces[$index] - 1 === $this->whitespaces[$index - 1]) {
                // consecutive whitespaces don't count as a word
                $r++;
            }
        }

        return [$index, $isAtStart];
    }

    private function extractStartRadiusAndWord(): void
    {
        $this->startWhitespaceIndex = $this->normalizedWhitespaceIndexes[$this->matches[$this->m]];
        $this->endWhitespaceIndex = $this->startWhitespaceIndex + 1;
        [$this->startWhitespaceIndexRadius, $this->isAtStart] = $this->startRadius($this->startWhitespaceIndex);

        $startPosRadius = $this->whitespaces[$this->startWhitespaceIndexRadius];
        $startPosWord = $this->whitespaces[$this->startWhitespaceIndex] + 1;
        $this->endPosWord = $this->whitespaces[$this->endWhitespaceIndex];

        $firstPart = mb_substr($this->text, $startPosRadius, $startPosWord - $startPosRadius);

        // punctuation also counts as word boundary, so the extract could start with the end of a cut off word (", word")
        if (!$this->isAtStart) {
            $firstPart = preg_replace('/^\s*[\p{P}\p{S}]+(?=\s)/u', '', $firstPart);
        }

        $this->addPart($firstPart);
        $this->addWord(mb_substr($this->text, $startPosWord, $this->endPosWord - $startPosWord));
    }

    private function setEndPosRadius(): void
    {
        $this->endWhitespaceIndexRadius = $this->endWhitespaceIndex;

        for ($r = $this->wordRadius; !$this->isAtEnd && $r > 0; $r--) {
            $this->endWhitespaceIndexRadius++;

            if ($this->endWhitespaceIndexRadius >= $this->nrOfWhitespaces - 1) {
                $this->isAtEnd = true;
                $this->endWhitespaceIndexRadius = $this->nrOfWhitespaces - 1;
            } elseif ($this->whitespaces[$this->endWhitespaceIndexRadius] + 1 === $this->whitespaces[$this->endWhitespaceIndexRadius + 1]) {
                // consecutive whitespaces don't count as a word
                $r++;
            }
        }

        $this->endPosRadius = $this->whitespaces[$this->endWhitespaceIndexRadius];
    }

    private function setNextMatch(): void
    {
        $this->nextMatchIndex = $this->m + 1;
        $this->hasWordInRadius = false;
        $this->nextMatchStartWhitespaceIndex = null;

        if (isset($this->matches[$this->nextMatchIndex])) {
            $this->nextMatchStartWhitespaceIndex = $this->normalizedWhitespaceIndexes[$this->matches[$this->nextMatchIndex]];
            // merge when the extract of the next match would touch or overlap this one,
            // otherwise text would be repeated or continuous text shown as separate extracts
            $this->hasWordInRadius = $this->startRadius($this->nextMatchStartWhitespaceIndex)[0] <= $this->endWhitespaceIndexRadius;
        }
    }

    private function extractNextPartAndWord(): void
    {
        $this->m = $this->nextMatchIndex;
        $startPosWord = $this->whitespaces[$this->nextMatchStartWhitespaceIndex] + 1;

        $this->addPart(mb_substr($this->text, $this->endPosWord, $startPosWord - $this->endPosWord));

        $this->endPosWord = $this->whitespaces[$this->nextMatchStartWhitespaceIndex + 1];
        $this->addWord(mb_substr($this->text, $startPosWord, $this->endPosWord - $startPosWord));
    }

    private function extractRest(): void
    {
        $this->addPart(mb_substr($this->text, $this->endPosWord, $this->endPosRadius - $this->endPosWord));
    }

    private function endRadiusExceedsText(): bool
    {
        return $this->endWhitespaceIndexRadius >= $this->nrOfWhitespaces - 1;
    }

    /**
     * Handles the end of an extract. Returns whether the extract can be expanded with the next match.
     */
    private function finishOrExpand(): bool
    {
        if ($this->endRadiusExceedsText()) {
            $this->isAtEnd = true;

            // take all remaining matches and the rest of the text
            while ($this->hasWordInRadius) {
                $this->extractNextPartAndWord();
                $this->setNextMatch();
            }

            $this->endPosRadius = $this->textLength;
            $this->extractRest();

            return false;
        }

        if (!$this->hasWordInRadius) {
            $this->extractRest();
            return false;
        }

        return true;
    }

    protected function buildExtracts(): void
    {
        for ($this->m = 0; $this->limit > count($this->extracts) && $this->m < $this->matchesCount; $this->m++) {
            $this->words = [];
            $this->extract = '';
            $this->extractParts = [];
            $this->isAtStart = false;
            $this->isAtEnd = false;

            $this->extractStartRadiusAndWord();
            $this->setEndPosRadius();
            $this->setNextMatch();

            // expand the radius as long as the next match is inside of it,
            // this prevents showing similar extracts multiple times
            while ($this->finishOrExpand() && !$this->isAtEnd) {
                $this->extractNextPartAndWord();

                $this->endWhitespaceIndex = $this->nextMatchStartWhitespaceIndex + 1;
                $this->setEndPosRadius();
                $this->setNextMatch();
            }

            $this->extracts[] = [
                'nr' => count($this->extracts) + 1,
                'text' => trim($this->extract),
                'textParts' => $this->extractParts,
                'words' => $this->words,
                'isAtStart' => $this->isAtStart,
                'isAtEnd' => $this->isAtEnd,
            ];
        }
    }
}
