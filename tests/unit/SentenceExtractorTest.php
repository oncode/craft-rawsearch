<?php

namespace oncode\rawsearch\tests\unit;

use oncode\rawsearch\helpers\SentenceExtractor;
use PHPUnit\Framework\TestCase;

class SentenceExtractorTest extends TestCase
{
    private function extract(string $text, string $phrase, int $maxLength = 200, int $radius = 2, int $limit = 10, int $mode = 2): SentenceExtractor
    {
        $extractor = new SentenceExtractor($text, $phrase, '[{phrase}]', $mode, $radius, $limit, true, $maxLength);
        $extractor->extract();

        return $extractor;
    }

    private function texts(SentenceExtractor $extractor): array
    {
        return array_column($extractor->getExtracts(), 'text');
    }

    public function testWholeSentences(): void
    {
        $e = $this->extract('The island is small. Quokkas live there. Rangers help them. The ferry is fast.', 'quokkas ferry');

        $this->assertSame(['[Quokkas] live there.', 'The [ferry] is fast.'], $this->texts($e));
        $this->assertSame(2, $e->getWordsFound());
        $this->assertSame([1, 2], array_column($e->getExtracts(), 'nr'));
        // not cut, so no "…"
        $this->assertTrue($e->getExtracts()[0]['isAtStart']);
        $this->assertTrue($e->getExtracts()[0]['isAtEnd']);
    }

    public function testNeighboringSentencesAreMerged(): void
    {
        $e = $this->extract('Intro here. Quokkas are cute. Ferry rides are fun. Outro.', 'quokkas ferry');

        $this->assertSame(['[Quokkas] are cute. [Ferry] rides are fun.'], $this->texts($e));
    }

    public function testNeighboringSentencesAreNotMergedOverMaxLength(): void
    {
        $e = $this->extract('Intro here. Quokkas are cute. Ferry rides are fun. Outro.', 'quokkas ferry', 30);

        $this->assertSame(['[Quokkas] are cute.', '[Ferry] rides are fun.'], $this->texts($e));
    }

    public function testMultipleWordsInOneSentence(): void
    {
        $e = $this->extract('Nothing. The quokka and the other quokka meet. End.', 'quokka');

        $this->assertSame(['The [quokka] and the other [quokka] meet.'], $this->texts($e));
        $this->assertSame(['quokka', 'quokka'], $e->getExtracts()[0]['words']);
    }

    public function testLongSentencesAreShortenedToTheRadius(): void
    {
        $long = 'This is a very long sentence that talks about many things and somewhere in the middle it mentions the quokka and then it goes on and on for a long time.';
        $e = $this->extract("Short one. $long Next.", 'quokka', 60, 2);

        $this->assertSame(['mentions the [quokka] and then'], $this->texts($e));
        // cut on both sides
        $this->assertFalse($e->getExtracts()[0]['isAtStart']);
        $this->assertFalse($e->getExtracts()[0]['isAtEnd']);
    }

    public function testShortenedAtSentenceStart(): void
    {
        $e = $this->extract('Quokkas are the happiest animals of the whole world and everyone who visits the island wants a picture with them.', 'quokkas', 40, 2);

        $this->assertSame(['[Quokkas] are the'], $this->texts($e));
        $this->assertTrue($e->getExtracts()[0]['isAtStart']);
        $this->assertFalse($e->getExtracts()[0]['isAtEnd']);
    }

    public function testParagraphsAreSentences(): void
    {
        $e = $this->extract('<h2>Quokka facts</h2><p>They are small</p><ul><li>cute quokka</li><li>other</li></ul>', 'quokka');

        // headings, paragraphs and list items are sentences on their own
        $this->assertSame(['[Quokka] facts', 'cute [quokka]'], $this->texts($e));
    }

    public function testLimit(): void
    {
        $e = $this->extract('One quokka. Two. Three quokka. Four. Five quokka.', 'quokka', 200, 2, 2);

        $this->assertCount(2, $e->getExtracts());
    }

    public function testEscapingAndDiacritics(): void
    {
        $e = $this->extract('Müller & Söhne <b>grüßen</b>. Other.', 'grussen muller');

        $this->assertSame(['[Müller] &amp; Söhne [grüßen].'], $this->texts($e));

        $e = $this->extract('A &lt;script&gt; quokka. Next.', 'quokka');
        $this->assertSame(['A &lt;script&gt; [quokka].'], $this->texts($e));
    }

    public function testExactMode(): void
    {
        $this->assertSame([], $this->texts($this->extract('Quokkas only.', 'quokka', 200, 2, 10, 1)));
        $this->assertSame(['A [quokka].'], $this->texts($this->extract('Quokkas only. A quokka.', 'quokka', 200, 2, 10, 1)));
    }

    public function testNoMatches(): void
    {
        $e = $this->extract('Nothing here.', 'quokka');

        $this->assertSame([], $e->getExtracts());
        $this->assertSame(0, $e->getWordsFound());
    }
}
