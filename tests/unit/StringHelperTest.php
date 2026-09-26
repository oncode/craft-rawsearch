<?php

namespace oncode\rawsearch\tests\unit;

use oncode\rawsearch\helpers\StringHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StringHelperTest extends TestCase
{
    public static function normalizeProvider(): array
    {
        return [
            'lowercase' => ['Hello World', 'hello world'],
            'diacritics' => ['Müller Söhne Café', 'muller sohne cafe'],
            'sharp s' => ['Grüße', 'grusse'],
            'ascii punctuation' => ['fish, chips; peas!', 'fish chips peas'],
            'unicode punctuation' => ['„Wort“ – «mot» …', 'wort mot'],
            'symbols' => ['a+b=c € 5$', 'a b c 5'],
            'html' => ['<p>Hello<br>World</p>', 'hello world'],
            'entities' => ['Fish&nbsp;&amp;&nbsp;Chips &lt;b&gt;', 'fish chips b'],
            'whitespace' => ["  a\n\n\tb   c ", 'a b c'],
            'numbers' => ['In 2024: 12 trees', 'in 2024 12 trees'],
            'underscore and percent' => ['100% some_thing', '100 some thing'],
            'empty' => ['', ''],
            'only punctuation' => ['?!…', ''],
            'cyrillic stays searchable' => ['Привет', 'privet'],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, StringHelper::normalize($input));
    }

    public function testNormalizeRemovesIgnoredWords(): void
    {
        $this->assertSame('cat dog', StringHelper::normalize('The cat and the dog', ['the', 'AND']));
    }

    public function testNormalizeIgnoresOnlyWholeWords(): void
    {
        $this->assertSame('theatre', StringHelper::normalize('the theatre', ['the']));
    }

    public function testNormalizeIgnoredWordsAreNormalizedToo(): void
    {
        $this->assertSame('welt', StringHelper::normalize('Grüße Welt', ['grüsse']));
    }

    public function testNormalizeIgnoredWordWithRegexCharacters(): void
    {
        $this->assertSame('a b', StringHelper::normalize('a c++ b', ['c++', '']));
    }

    public function testRemovePunctuationKeepsCharacterCount(): void
    {
        $text = 'Grüße, „Müller“ – 5€!';
        $this->assertSame(mb_strlen($text), mb_strlen(StringHelper::removePunctuation($text)));
    }

    public function testStripHtml(): void
    {
        $this->assertSame(' a  b  &lt; c', StringHelper::stripHtml('<p>a</p><p>b</p> &amp;lt; c'));
        $this->assertSame('a b', StringHelper::stripHtml("a\u{00A0}b"));
    }

    public function testEscapeLike(): void
    {
        $this->assertSame('50\% a\_b c\\\\d', StringHelper::escapeLike('50% a_b c\d'));
    }
}
