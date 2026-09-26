<?php

namespace oncode\rawsearch\tests\unit;

use oncode\rawsearch\helpers\IndexHelper;
use PHPUnit\Framework\TestCase;

class IndexHelperTest extends TestCase
{
    public function testTruncateKeepsShortStrings(): void
    {
        $this->assertSame('short text', IndexHelper::truncate('short text'));
    }

    public function testTruncateDoesNotCutWordsOrCharacters(): void
    {
        $str = str_repeat('wörd ', (int)(IndexHelper::MAX_COLUMN_BYTES / 6) + 100);
        $truncated = IndexHelper::truncate($str);

        $this->assertLessThanOrEqual(IndexHelper::MAX_COLUMN_BYTES, strlen($truncated));
        $this->assertTrue(mb_check_encoding($truncated, 'UTF-8'));
        $this->assertStringEndsWith('wörd ', $truncated);
    }

    public function testFulltextWordLength(): void
    {
        if (!IndexHelper::supportsFulltext()) {
            $this->assertFalse(IndexHelper::isFulltextWord('something'));
            return;
        }

        $min = IndexHelper::getMinWordLength();
        $this->assertFalse(IndexHelper::isFulltextWord(str_repeat('a', $min - 1)));
        $this->assertTrue(IndexHelper::isFulltextWord(str_repeat('a', $min)));
        $this->assertFalse(IndexHelper::isFulltextWord(str_repeat('a', IndexHelper::getMaxWordLength() + 1)));
    }

    public function testPrepareText(): void
    {
        // paragraphs become lines, they separate sentences
        $this->assertSame("Hello,\nWorld & more", IndexHelper::prepareText("<p>Hello,</p>\n\n<p>World &amp; more</p>"));
        $this->assertSame("A\nB\nC", IndexHelper::prepareText("A<br>B\r\n  \n C"));
        $this->assertSame('Say hello.', IndexHelper::prepareText('Say <strong>hello</strong>.'));
    }
}
