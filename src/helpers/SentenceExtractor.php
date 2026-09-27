<?php

namespace oncode\rawsearch\helpers;

/**
 * Extracts the sentences that contain the found words.
 *
 * Neighboring sentences with found words become one extract as long as they fit into `maxLength`.
 * Sentences that are longer than `maxLength` are shortened to the word radius around the found words.
 * `isAtStart`/`isAtEnd` of an extract are false where it was cut, so "…" is shown exactly there.
 */
class SentenceExtractor extends WordRadiusExtractor
{
    /**
     * @param int $maxLength Max characters of an extract, longer sentences are shortened to the word radius
     * @see WordRadiusExtractor::__construct() for the other params
     */
    public function __construct(
        string $text,
        string $phrase,
        string $wrap = '',
        int $wordMode = self::MODE_WORD_START,
        int $wordRadius = 4,
        int $limit = 10,
        bool $isHtml = true,
        protected int $maxLength = 200,
    ) {
        parent::__construct($text, $phrase, $wrap, $wordMode, $wordRadius, $limit, $isHtml);
    }

    protected function buildExtracts(): void
    {
        $words = $this->matchedWordRanges();

        if (empty($words)) {
            return;
        }

        foreach ($this->sentenceBlocks($words) as $block) {
            if (count($this->extracts) >= $this->limit) {
                break;
            }

            if ($block['end'] - $block['start'] <= $this->maxLength) {
                $this->addSentenceExtract($block);
            } else {
                $this->addShortenedExtracts($block);
            }
        }

        foreach ($this->extracts as $i => $extract) {
            $this->extracts[$i]['nr'] = $i + 1;
        }
    }

    /**
     * Groups the found words by sentence and merges neighboring sentences that fit into maxLength.
     *
     * @param array<array{0: int, 1: int}> $words
     * @return array<array{start: int, end: int, lastSentence: int, words: array}>
     */
    private function sentenceBlocks(array $words): array
    {
        $sentences = SentenceSplitter::split($this->text);
        $sentenceCount = count($sentences);
        $blocks = [];
        $s = 0;

        foreach ($words as $word) {
            // find the sentence that contains the word
            while ($s < $sentenceCount && $sentences[$s][1] <= $word[0]) {
                $s++;
            }

            if ($s >= $sentenceCount) {
                break;
            }

            $last = count($blocks) - 1;

            if ($last >= 0 && $blocks[$last]['lastSentence'] === $s) {
                // same sentence
                $blocks[$last]['words'][] = $word;
            } elseif (
                $last >= 0
                && $blocks[$last]['lastSentence'] === $s - 1
                && $sentences[$s][1] - $blocks[$last]['start'] <= $this->maxLength
            ) {
                // the next sentence, the extract would be continuous text
                $blocks[$last]['end'] = $sentences[$s][1];
                $blocks[$last]['lastSentence'] = $s;
                $blocks[$last]['words'][] = $word;
            } else {
                $blocks[] = [
                    'start' => $sentences[$s][0],
                    'end' => $sentences[$s][1],
                    'lastSentence' => $s,
                    'words' => [$word],
                ];
            }
        }

        return $blocks;
    }

    private function addSentenceExtract(array $block): void
    {
        $html = '';
        $parts = [];
        $foundWords = [];
        $pos = $block['start'];

        foreach ($block['words'] as [$wordStart, $wordEnd]) {
            $part = $this->oneLine(mb_substr($this->text, $pos, $wordStart - $pos));
            $word = mb_substr($this->text, $wordStart, $wordEnd - $wordStart);

            $parts[] = $part;
            $parts[] = $word;
            $foundWords[] = $word;
            $html .= StringHelper::encodeHtml($part) . $this->wrapWord($word);
            $pos = $wordEnd;
        }

        $rest = $this->oneLine(mb_substr($this->text, $pos, $block['end'] - $pos));
        $parts[] = $rest;
        $html .= StringHelper::encodeHtml($rest);

        $this->wordsFound += count($foundWords);
        $this->extracts[] = [
            'nr' => 0,
            'text' => $html,
            'textParts' => $parts,
            'words' => $foundWords,
            'isAtStart' => true,
            'isAtEnd' => true,
        ];
    }

    /**
     * Shortens a too long sentence to the word radius around the found words.
     */
    private function addShortenedExtracts(array $block): void
    {
        $extractor = new WordRadiusExtractor(
            mb_substr($this->text, $block['start'], $block['end'] - $block['start']),
            implode(' ', $this->phraseWords),
            $this->wrap,
            $this->wordMode,
            $this->wordRadius,
            $this->limit - count($this->extracts),
            false,
        );
        $extractor->extract();

        $this->wordsFound += $extractor->getWordsFound();
        array_push($this->extracts, ...$extractor->getExtracts());
    }

    private function oneLine(string $str): string
    {
        return strtr($str, ["\n" => ' ', "\r" => ' ']);
    }
}
