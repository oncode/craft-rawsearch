<?php

namespace oncode\rawsearch\tests\unit;

use oncode\rawsearch\helpers\WordRadiusExtractor;
use PHPUnit\Framework\TestCase;

class WordRadiusExtractorTest extends TestCase
{
    private function extract(string $text, string $phrase, int $mode = 2, int $radius = 2, int $limit = 10, string $wrap = '[{phrase}]'): WordRadiusExtractor
    {
        $extractor = new WordRadiusExtractor($text, $phrase, $wrap, $mode, $radius, $limit);
        $extractor->extract();

        return $extractor;
    }

    private function texts(WordRadiusExtractor $extractor): array
    {
        return array_column($extractor->getExtracts(), 'text');
    }

    public function testRadius(): void
    {
        $e = $this->extract('one two three four five six seven eight nine', 'five');
        $this->assertSame(['three four [five] six seven'], $this->texts($e));
        $this->assertFalse($e->getExtracts()[0]['isAtStart']);
        $this->assertFalse($e->getExtracts()[0]['isAtEnd']);
        $this->assertSame(1, $e->getWordsFound());
    }

    public function testStartAndEnd(): void
    {
        $e = $this->extract('one two three', 'one', 2, 5);
        $this->assertSame(['[one] two three'], $this->texts($e));
        $this->assertTrue($e->getExtracts()[0]['isAtStart']);
        $this->assertTrue($e->getExtracts()[0]['isAtEnd']);
    }

    public function testCloseMatchesAreMerged(): void
    {
        $e = $this->extract('a b test c test d e f g h i j test k', 'test', 2, 2);
        $this->assertSame(['a b [test] c [test] d e', 'i j [test] k'], $this->texts($e));
        $this->assertSame(3, $e->getWordsFound());
        $this->assertSame([1, 2], array_column($e->getExtracts(), 'nr'));
    }

    public function testAllMatchesAndRestAtTheEnd(): void
    {
        $e = $this->extract('zero one two test x test y.', 'test');
        $this->assertSame(['one two [test] x [test] y.'], $this->texts($e));
        $this->assertTrue($e->getExtracts()[0]['isAtEnd']);
        $this->assertSame(['test', 'test'], $e->getExtracts()[0]['words']);
    }

    public function testModes(): void
    {
        $text = 'recycle recycled bicycle cycle';
        $this->assertSame(['cycle'], $this->extract($text, 'cycle', WordRadiusExtractor::MODE_EXACT, 0)->getExtracts()[0]['words']);
        $this->assertSame(['cycle'], array_merge(...array_column($this->extract($text, 'cycle', WordRadiusExtractor::MODE_WORD_START, 0)->getExtracts(), 'words')));
        $this->assertSame(
            ['recycle', 'recycled', 'bicycle', 'cycle'],
            array_merge(...array_column($this->extract($text, 'cycle', WordRadiusExtractor::MODE_WORD_CONTENT, 0)->getExtracts(), 'words'))
        );
    }

    public function testExactModeFindsRepeatedWords(): void
    {
        $e = $this->extract('test test test', 'test', WordRadiusExtractor::MODE_EXACT, 1);
        $this->assertSame(3, $e->getWordsFound());
    }

    public function testMultipleWords(): void
    {
        $e = $this->extract('the quick brown fox jumps over the lazy dog', 'quick dog', 2, 1);
        $this->assertSame(['the [quick] brown', 'lazy [dog]'], $this->texts($e));
    }

    public function testDiacriticsAndPunctuation(): void
    {
        $e = $this->extract('Viele Grüße, Herr Müller!', 'grusse muller', 2, 1);
        $this->assertSame(['Grüße', 'Müller'], array_merge(...array_column($e->getExtracts(), 'words')));
        $this->assertStringContainsString('[Grüße],', $this->texts($e)[0]);
    }

    public function testNonLatinText(): void
    {
        $e = $this->extract('日本語 テキスト with Ünïcödé wörds and Привет мир', 'unicode privet', 2, 1);
        // continuous text, so one extract
        $this->assertSame(['with [Ünïcödé] wörds and [Привет] мир'], $this->texts($e));
    }

    public function testOutputIsEscaped(): void
    {
        $e = $this->extract('<p>a &lt;script&gt;alert(1)&lt;/script&gt; test</p>', 'test', 2, 5, 10, '<mark>{phrase}</mark>');
        $text = $this->texts($e)[0];
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringContainsString('&lt;script&gt;', $text);
        $this->assertStringContainsString('<mark>test</mark>', $text);
    }

    public function testLimit(): void
    {
        $e = $this->extract('a x b c d e x f g h i x', 'x', 2, 0, 2);
        $this->assertCount(2, $e->getExtracts());
    }

    public function testNoMatchesAndEmptyInput(): void
    {
        $this->assertSame([], $this->extract('some text', 'nothing')->getExtracts());
        $this->assertSame([], $this->extract('', 'test')->getExtracts());
        $this->assertSame([], $this->extract('some text', '')->getExtracts());
        $this->assertSame([], $this->extract('some text', 'some', 2, 2, 0)->getExtracts());
    }

    public function testNoLeadingPunctuationOfCutOffWord(): void
    {
        $this->assertSame(['water points [test]'], $this->texts($this->extract('Every ranger helps: fences, water points test', 'test', 2, 2)));
        // punctuation that belongs to the next word is kept
        $this->assertSame(['&quot;[test]'], $this->texts($this->extract('see "test"', 'test', 2, 0)));
        // at the start of the text nothing is removed
        $this->assertSame(['- [test]'], $this->texts($this->extract('- test', 'test', 2, 3)));
    }

    public function testTouchingExtractsAreMerged(): void
    {
        // "der Test die" and "der Test was" are continuous text
        $e = $this->extract(' der Test die der Test was wie Test wo wo wo wo wo wo ', 'test', 2, 1);
        $this->assertSame(['der [Test] die der [Test] was wie [Test] wo'], $this->texts($e));
        $this->assertSame(3, $e->getWordsFound());
    }

    public function testOverlappingExtractsDontRepeatText(): void
    {
        // html input gets its whitespace collapsed
        $e = $this->extract(' der das die  der Test die der Test was wie Test wo wo  wo wo wo wo ', 'test', 2, 2);
        $this->assertSame(['die der [Test] die der [Test] was wie [Test] wo wo'], $this->texts($e));

        // plain text keeps it, consecutive whitespaces don't count as words
        $e = new \oncode\rawsearch\helpers\WordRadiusExtractor(' der das die  der Test die der Test was wie Test wo wo  wo wo wo wo ', 'test', '[{phrase}]', 2, 2, 10, false);
        $e->extract();
        $this->assertSame(['die  der [Test] die der [Test] was wie [Test] wo wo'], $this->texts($e));
    }

    public function testWordStartDoesNotMatchInsideWords(): void
    {
        $text = ' der dddTestsaite die der Test der der Test ';

        $this->assertSame(['Test', 'Test'], array_merge(...array_column($this->extract($text, 'test', 2, 1)->getExtracts(), 'words')));
        $this->assertSame(['dddTestsaite', 'Test', 'Test'], array_merge(...array_column($this->extract($text, 'test', 3, 1)->getExtracts(), 'words')));
    }

    public function testSeparateExtractsAtStartAndEnd(): void
    {
        $e = $this->extract(' der Test der der der der der der Test der ', 'test', 2, 2);

        $this->assertSame(['der [Test] der der', 'der der [Test] der'], $this->texts($e));
        $this->assertTrue($e->getExtracts()[0]['isAtStart']);
        $this->assertTrue($e->getExtracts()[1]['isAtEnd']);
    }

    public function testRadiusZero(): void
    {
        $this->assertSame(['[b]'], $this->texts($this->extract('a b c', 'b', 2, 0)));
    }

    public function testEmptyWrap(): void
    {
        $this->assertSame(['a b c'], $this->texts($this->extract('a b c', 'b', 2, 1, 10, '')));
    }

    public function testRegexCharactersInPhrase(): void
    {
        $this->assertSame([], $this->extract('a b c', 'a/b(')->getExtracts());
    }
}
