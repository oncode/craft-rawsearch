<?php

namespace oncode\rawsearch\tests\unit;

use oncode\rawsearch\helpers\SentenceSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SentenceSplitterTest extends TestCase
{
    private function sentences(string $text): array
    {
        return array_map(fn($s) => mb_substr($text, $s[0], $s[1] - $s[0]), SentenceSplitter::split($text));
    }

    public static function splitProvider(): array
    {
        return [
            'simple' => ['One. Two! Three? Four', ['One.', 'Two!', 'Three?', 'Four']],
            'ellipsis' => ['Wait… Then more.', ['Wait…', 'Then more.']],
            'multiple punctuation' => ['Really?! Yes.', ['Really?!', 'Yes.']],
            'quotes and brackets' => ['He said "Stop." Then (it ended.) Next «Oui.» Fin', ['He said "Stop."', 'Then (it ended.)', 'Next «Oui.»', 'Fin']],
            'next sentence starts with a quote' => ['End. „Neuer Satz" hier.', ['End.', '„Neuer Satz" hier.']],
            'next sentence starts with a number' => ['End. 2024 was good.', ['End.', '2024 was good.']],
            'lowercase continuation' => ['This is approx. two metres. And more.', ['This is approx. two metres.', 'And more.']],
            'dotted abbreviations' => ['Nimm z.B. Äpfel. Oder e.g. Pears. Ok.', ['Nimm z.B. Äpfel.', 'Oder e.g. Pears.', 'Ok.']],
            'abbreviation list' => ['Dr. Müller kommt. Herr Nr. 5 auch. Ca. 10 Leute.', ['Dr. Müller kommt.', 'Herr Nr. 5 auch.', 'Ca. 10 Leute.']],
            'german date' => ['Am 12. Mai 2024 war es. Dann kam der 1. Juni.', ['Am 12. Mai 2024 war es.', 'Dann kam der 1. Juni.']],
            'decimals and urls' => ['It costs 3.50 CHF on example.com today. Yes.', ['It costs 3.50 CHF on example.com today.', 'Yes.']],
            'single initial' => ['John F. Kennedy spoke. Then.', ['John F. Kennedy spoke.', 'Then.']],
            'line breaks' => ["Heading\nFirst sentence. Second\n\n- list item\n- other item", ['Heading', 'First sentence.', 'Second', '- list item', '- other item']],
            'line break after lowercase sentence end' => ["see z.B.\nnext line", ['see z.B.', 'next line']],
            'no punctuation' => ['just some words without an end', ['just some words without an end']],
            'surrounding whitespace' => ['  One.   Two.  ', ['One.', 'Two.']],
            'empty' => ['', []],
            'unicode' => ['Привет мир. Как дела? 日本語。', ['Привет мир.', 'Как дела?', '日本語。']],
        ];
    }

    #[DataProvider('splitProvider')]
    public function testSplit(string $text, array $expected): void
    {
        $this->assertSame($expected, $this->sentences($text));
    }

    public function testOffsetsAreCharacters(): void
    {
        $text = 'Grüße aus Zürich. Äpfel!';

        $this->assertSame([[0, 17], [18, 24]], SentenceSplitter::split($text));
    }
}
