<?php

namespace oncode\rawsearch\tests\integration;

use InvalidArgumentException;
use oncode\rawsearch\events\TermsResolving;
use oncode\rawsearch\events\WordElementDataResolving;
use oncode\rawsearch\tests\TestCase;

class AutocompleteTest extends TestCase
{
    private function words(string $query, array $params = []): array
    {
        return array_column($this->plugin()->autocomplete->search(['query' => $query] + $params), 'word');
    }

    public function testCompletesWordStarts(): void
    {
        $this->assertSame(['quokka', 'quokkas', 'quokkawatchers'], $this->words('quok'));
    }

    public function testCaseVariantsAreMerged(): void
    {
        // "Quokka" (title) and "quokka" (text) are one word, the most frequent spelling is used
        $words = $this->plugin()->autocomplete->search(['query' => 'quokka']);
        $quokka = $words[0];

        $this->assertSame('quokka', $quokka['word']);
        $this->assertContains(self::$fixture->ids['quokka'], array_column($quokka['elements'], 'id'));
    }

    public function testElementsAreSortedByOccurrences(): void
    {
        $quokka = $this->plugin()->autocomplete->search(['query' => 'quokka'])[0];
        $counts = array_column($quokka['elements'], 'count');
        $sorted = $counts;
        rsort($sorted);

        $this->assertSame(self::$fixture->ids['quokka'], $quokka['elements'][0]['id']);
        $this->assertSame($sorted, $counts);
        $this->assertGreaterThan(1, $counts[0]);
    }

    public function testResultsMatchTheSearch(): void
    {
        foreach (['quok', 'euc', 'rottnest is', 'ferr', 'pla'] as $query) {
            foreach ($this->plugin()->autocomplete->search(['query' => $query]) as $word) {
                $this->assertSame(
                    $this->search($word['word'])['total'],
                    $word['results'],
                    "Results of the suggestion \"{$word['word']}\""
                );
            }
        }
    }

    public function testResultsIncludeLongerWords(): void
    {
        $words = array_column($this->plugin()->autocomplete->search(['query' => 'quokka']), null, 'word');

        // "quokka" is in 2 live entries, the search also finds "quokkawatchers" of the ferry entry
        $this->assertCount(2, $words['quokka']['elements']);
        $this->assertSame(3, $words['quokka']['results']);
        $this->assertSame(1, $words['quokkawatchers']['results']);
    }

    public function testResultsWithLimitAndSites(): void
    {
        $words = $this->plugin()->autocomplete->search(['query' => 'quok', 'limit' => 1]);
        $this->assertSame(3, $words[0]['results']);

        $words = $this->plugin()->autocomplete->search(['query' => 'fah', 'site' => self::$fixture->deSite->handle]);
        foreach ($words as $word) {
            $this->assertSame($this->search($word['word'], ['site' => self::$fixture->deSite->handle])['total'], $word['results']);
        }
    }

    public function testBlacklistedWordsAreNotSuggested(): void
    {
        $this->setSettings(['blacklistedWords' => 'rottnest']);

        $this->assertSame([], $this->words('rottn'));
        // blacklisted words that were typed stay, the search ignores them
        $words = $this->plugin()->autocomplete->search(['query' => 'rottnest is']);
        $this->assertSame(['Rottnest Island'], array_column($words, 'word'));
        $this->assertSame($this->search('rottnest island')['total'], $words[0]['results']);
        $this->assertSame([], $this->words('rottnest'));
    }

    public function testWordsOfNotLiveEntriesDontLeak(): void
    {
        $this->assertSame([], $this->words('schedul'));
        $this->assertSame([], $this->words('expire'));
        $this->assertSame(['scheduled'], $this->words('schedul', ['status' => null]));
    }

    public function testKeepsDiacritics(): void
    {
        $site = ['site' => self::$fixture->deSite->handle];

        $this->assertSame(['Grüße'], $this->words('gru', $site));
        // slugs don't count, "fährplan" would compete with the title "Fährplan"
        $this->assertSame(['Fähre', 'Fähren', 'Fährplan'], $this->words('fah', $site));
    }

    public function testMultipleWords(): void
    {
        $this->assertSame(['Rottnest Island'], $this->words('rottnest is'));
    }

    public function testLimitKeepsMostFrequentWords(): void
    {
        $this->assertSame(['quokka'], $this->words('quok', ['limit' => 1]));
        $this->assertCount(3, $this->words('quok', ['limit' => 10]));
    }

    public function testElementTypesAndSites(): void
    {
        $this->assertSame([], $this->words('quok', ['elementTypes' => 'asset']));
        // German nouns are capitalized
        $this->assertSame(['Quokka', 'Quokkas'], $this->words('quok', ['site' => self::$fixture->deSite->handle]));
    }

    public function testEmptyQuery(): void
    {
        $this->assertSame([], $this->words('?!'));

        $this->expectException(InvalidArgumentException::class);
        $this->words('');
    }

    public function testEvents(): void
    {
        $this->on(WordElementDataResolving::class, function(WordElementDataResolving $event) {
            $event->elementData['textLength'] = mb_strlen($event->row['text']);
        });
        $this->on(TermsResolving::class, function(TermsResolving $event) {
            $event->rows = array_reverse($event->rows);
        });

        $words = $this->plugin()->autocomplete->search(['query' => 'quok']);

        $this->assertSame('quokkawatchers', $words[0]['word']);
        $this->assertIsInt($words[0]['elements'][0]['textLength']);
    }
}
