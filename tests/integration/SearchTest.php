<?php

namespace oncode\rawsearch\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\web\twig\variables\Paginate;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\events\DbQueryEvent;
use oncode\rawsearch\events\ElementQueryEvent;
use oncode\rawsearch\events\RowsEvent;
use oncode\rawsearch\events\SearchEvent;
use oncode\rawsearch\events\WeightScoreEvent;
use oncode\rawsearch\services\Search;
use oncode\rawsearch\services\Sort;
use oncode\rawsearch\tests\TestCase;
use yii\base\InvalidArgumentException;

class SearchTest extends TestCase
{
    public function testWordStartFindsLiveEntriesOnly(): void
    {
        $titles = $this->titles('quokka');

        $this->assertEqualsCanonicalizing(
            ['Quokka habitat protection', 'Ferry timetable', 'Wombat burrows'],
            $titles
        );
    }

    public function testStatusParam(): void
    {
        $titles = $this->titles('quokka', ['status' => null]);

        $this->assertContains('Future quokka entry', $titles);
        $this->assertContains('Expired quokka entry', $titles);
        $this->assertNotContains('Disabled quokka entry', $titles);
    }

    public function testExactMode(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Quokka habitat protection', 'Wombat burrows'],
            $this->titles('quokka', ['mode' => Search::MODE_EXACT])
        );
    }

    public function testWordContentMode(): void
    {
        $this->assertSame(['Ferry timetable'], $this->titles('kawatch', ['mode' => Search::MODE_WORD_CONTENT]));
        $this->assertSame([], $this->titles('kawatch'));
    }

    public function testAndMatchesWordsInDifferentFields(): void
    {
        // "eucalyptus" is in a Matrix block, "rangers" in the intro
        $this->assertSame(['Quokka habitat protection'], $this->titles('eucalyptus rangers'));
    }

    public function testAndRequiresAllWords(): void
    {
        $this->assertSame([], $this->titles('cyclists xylophonist'));
    }

    public function testOr(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Ferry timetable', 'Quokka habitat protection'],
            $this->titles('cyclists xylophonist', ['or' => true])
        );
    }

    public function testShortWordsAreFound(): void
    {
        $this->assertSame(['About the eucalyptus project'], $this->titles('47'));
        $this->assertSame(['About the eucalyptus project'], $this->titles('47', ['mode' => Search::MODE_EXACT]));
        $this->assertSame([], $this->titles('4', ['mode' => Search::MODE_EXACT]));
    }

    public function testCommonWordsAreNotStopwords(): void
    {
        $this->assertContains('About the eucalyptus project', $this->titles('about'));
        $this->assertContains('About the eucalyptus project', $this->titles('the eucalyptus project'));
    }

    public function testLongWordIsFound(): void
    {
        $this->assertSame(['Ferry timetable'], $this->titles('quokkawatchers'));
    }

    public function testCaseAndDiacriticsDontMatter(): void
    {
        $site = ['site' => self::$fixture->deSite->handle];

        $this->assertSame(['Quokka Lebensraum'], $this->titles('GRÜSSE', $site));
        $this->assertSame(['Quokka Lebensraum'], $this->titles('muller', $site));
        $this->assertSame(['Fährplan'], $this->titles('fahre', $site));
    }

    public function testSites(): void
    {
        $deSite = self::$fixture->deSite;

        $this->assertSame([], $this->titles('velofahrer'));
        $this->assertSame(['Fährplan'], $this->titles('velofahrer', ['site' => $deSite->handle]));
        $this->assertSame(['Fährplan'], $this->titles('velofahrer', ['site' => $deSite->id]));
        $this->assertSame(['Fährplan'], $this->titles('velofahrer', ['site' => $deSite]));
        // disabled in the German site
        $this->assertSame([], $this->titles('wombats', ['site' => $deSite->handle]));
    }

    public function testInvalidSite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->search('quokka', ['site' => 'doesNotExist']);
    }

    public function testElementTypes(): void
    {
        $this->assertCount(3, $this->titles('quokka', ['elementTypes' => ['entry']]));
        $this->assertCount(3, $this->titles('quokka', ['elementTypes' => 'Entry,Asset']));
        $this->assertCount(3, $this->titles('quokka', ['elementTypes' => [Entry::class]]));
        $this->assertSame([], $this->titles('quokka', ['elementTypes' => 'asset']));
    }

    public function testInvalidElementType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->search('quokka', ['elementTypes' => ['nope']]);
    }

    public function testEmptyQueryThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->search('   ');
    }

    public function testQueryWithoutWords(): void
    {
        $search = $this->search('?! …');

        $this->assertSame([], $search['results']);
        $this->assertSame(0, $search['total']);
        $this->assertInstanceOf(Paginate::class, $search['pagination']);
    }

    public function testSpecialCharactersInQuery(): void
    {
        // punctuation is ignored like in the index, so this is a search for "quokka"
        $this->assertCount(3, $this->titles('% _ \\ "quokka +-*'));
        $this->assertSame([], $this->titles('%'));
        $this->assertSame([], $this->titles("'; DROP TABLE x; --"));
    }

    public function testBlacklistedWordsAreIgnoredInTheQuery(): void
    {
        $this->setSettings(['blacklistedWords' => 'the']);
        $this->reindexEntries();

        $this->assertSame(['Quokka habitat protection'], $this->titles('the rottnest'));
        $this->assertSame([], $this->titles('the'));

        $this->setSettings(['blacklistedWords' => '']);
        $this->reindexEntries();
    }

    public function testResultStructure(): void
    {
        $search = $this->search('rottnest');
        $result = $search['results'][0];

        $this->assertSame(1, $search['total']);
        $this->assertInstanceOf(Entry::class, $result['element']);
        $this->assertSame(self::$fixture->ids['quokka'], $result['elementId']);
        $this->assertSame('Quokka habitat protection', $result['title']);
        $this->assertSame('quokka-habitat-protection', $result['slug']);
        $this->assertStringEndsWith('/rawsearch-test/quokka-habitat-protection', $result['url']);
        $this->assertSame(1, $result['wordsFound']);
        $this->assertSame('<mark>Rottnest</mark> Island is home to', $result['extracts'][0]['text']);
        $this->assertTrue($result['extracts'][0]['isAtStart']);
        $this->assertIsInt($result['score']);

        foreach ($result['rows'] as $row) {
            $this->assertArrayNotHasKey('text', $row);
            $this->assertArrayNotHasKey('normalizedWords', $row);
        }
    }

    public function testSnippetsAreEscaped(): void
    {
        $text = implode(' ', array_column($this->search('alert', ['extract' => ['radius' => 10]])['results'][0]['extracts'], 'text'));

        $this->assertStringContainsString('works &amp; it pays off', $text);
        $this->assertStringContainsString('&lt;script&gt;<mark>alert</mark>(1)&lt;/script&gt;', $text);
    }

    public function testExtractOptions(): void
    {
        $result = $this->search('quokka', ['extract' => ['radius' => 1, 'limit' => 1, 'wrap' => '<b>{phrase}</b>']])['results'];
        $quokka = array_values(array_filter($result, fn($r) => $r['title'] === 'Quokka habitat protection'))[0];

        $this->assertCount(1, $quokka['extracts']);
        $this->assertSame('the <b>quokka</b> habitat.', $quokka['extracts'][0]['text']);
    }

    public function testExtractCanBeDisabled(): void
    {
        foreach ([false, ['enabled' => false]] as $extract) {
            $result = $this->search('quokka', ['extract' => $extract])['results'][0];
            $this->assertArrayNotHasKey('extracts', $result);
        }
    }

    public function testPagination(): void
    {
        $page2 = $this->search('quokka', ['resultsPerPage' => 2, 'page' => 2]);

        $this->assertSame(3, $page2['total']);
        $this->assertCount(1, $page2['results']);
        $this->assertSame(2, $page2['pagination']->currentPage);
        $this->assertSame(2, $page2['pagination']->totalPages);
        $this->assertSame(3, $page2['pagination']->first);
        $this->assertSame(3, $page2['pagination']->last);

        $page1 = $this->search('quokka', ['resultsPerPage' => 2, 'page' => '1']);
        $this->assertCount(2, $page1['results']);
        $this->assertNotContains($page2['results'][0]['title'], array_column($page1['results'], 'title'));

        $this->assertSame(2, $this->search('quokka', ['resultsPerPage' => 2, 'page' => 99])['pagination']->currentPage);
        $this->assertSame(1, $this->search('quokka', ['resultsPerPage' => 2, 'page' => 0])['pagination']->currentPage);
        $this->assertCount(1, $this->search('quokka', ['resultsPerPage' => 0])['results']);
    }

    public function testTitleMatchesComeFirst(): void
    {
        $this->assertSame('About the eucalyptus project', $this->titles('eucalyptus')[0]);
    }

    public function testFieldWeightChangesOrder(): void
    {
        // the ferry is mentioned in the title of the ferry entry and in a Matrix block of the quokka entry
        $this->assertSame('Ferry timetable', $this->titles('ferry')[0]);

        $blockText = Craft::$app->getFields()->getFieldByHandle('rsBlockText');
        $this->plugin()->fieldConfigs->saveMatchWeights($blockText->id, 30000, 30000);

        $this->assertSame('Quokka habitat protection', $this->titles('ferry')[0]);
    }

    public function testDefaultWeightSettings(): void
    {
        $this->setSettings(['titleMatchWeight' => 0, 'partialTitleMatchWeight' => 0, 'fieldMatchWeight' => 1000]);

        $this->assertSame('Quokka habitat protection', $this->titles('eucalyptus')[0]);
    }

    public function testScoresAreSortedDescending(): void
    {
        $scores = array_column($this->search('quokka')['results'], 'score');
        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
    }

    public function testWeightedSortCanBeDisabled(): void
    {
        $results = $this->search('quokka', ['weightedSort' => false])['results'];

        $this->assertCount(3, $results);
        $this->assertArrayNotHasKey('score', $results[0]);
    }

    public function testRowLimit(): void
    {
        $this->setSettings(['rowLimitSearch' => 1]);

        $this->assertCount(1, $this->titles('quokka'));
    }

    public function testElementQueryEvent(): void
    {
        $this->on(Search::class, Search::EVENT_MODIFY_ELEMENT_QUERY, function(ElementQueryEvent $event) {
            if ($event->query instanceof EntryQuery) {
                $event->query->title('Ferry timetable');
            }
        });

        $search = $this->search('quokka');
        $this->assertSame(['Ferry timetable'], array_column($search['results'], 'title'));
        // the total is correct too
        $this->assertSame(1, $search['total']);
    }

    public function testReadmeExampleAgeCutoff(): void
    {
        // the README example with the fixture section instead of "news"
        $cutoff = function(string $date) {
            return function(ElementQueryEvent $event) use ($date) {
                if ($event->query instanceof EntryQuery) {
                    $event->query->andWhere(['or',
                        ['not', ['entries.sectionId' => self::$fixture->section->id]],
                        ['>=', 'entries.postDate', \craft\helpers\Db::prepareDateForDb(new \DateTime($date))],
                    ]);
                }
            };
        };

        $this->on(Search::class, Search::EVENT_MODIFY_ELEMENT_QUERY, $cutoff('-3 years'));
        $this->assertCount(3, $this->titles('quokka'));

        // all fixture entries are older than tomorrow
        $this->on(Search::class, Search::EVENT_MODIFY_ELEMENT_QUERY, $cutoff('+1 day'));
        $search = $this->search('quokka');
        $this->assertSame([], $search['results']);
        $this->assertSame(0, $search['total']);
    }

    public function testReadmeExampleSectionBoost(): void
    {
        $scores = fn() => array_column($this->search('quokka')['results'], 'score', 'title');
        $before = $scores();

        $this->on(Search::class, Search::EVENT_MODIFY_SEARCH_QUERY, function(DbQueryEvent $event) {
            $event->dbQuery
                ->addSelect(['entries.sectionId'])
                ->leftJoin(['entries' => \craft\db\Table::ENTRIES], '[[entries.id]] = [[rawsearch.elementId]]');
        });
        $this->on(Sort::class, Sort::EVENT_ADD_WEIGHT_SCORE, function(WeightScoreEvent $event) {
            $sectionId = $event->elementRow['rows'][0]['sectionId'] ?? null;

            if ((int)$sectionId === self::$fixture->section->id) {
                $event->score += 3000;
            }
        });

        $after = $scores();

        foreach ($before as $title => $score) {
            $this->assertSame($score + 3000, $after[$title], $title);
        }
    }

    public function testJoinsDontCauseAmbiguousColumns(): void
    {
        $this->on(Search::class, Search::EVENT_MODIFY_SEARCH_QUERY, function(DbQueryEvent $event) {
            // elements has columns like `type` and `dateCreated` too
            $event->dbQuery->innerJoin(['elements' => \craft\db\Table::ELEMENTS], '[[elements.id]] = [[rawsearch.elementId]]');
        });

        $this->assertCount(3, $this->titles('quokka', ['elementTypes' => 'entry']));
        $this->assertCount(3, $this->titles('ok', ['mode' => Search::MODE_WORD_CONTENT, 'elementTypes' => 'entry']));
    }

    public function testSearchQueryEvent(): void
    {
        $this->on(Search::class, Search::EVENT_MODIFY_SEARCH_QUERY, function(DbQueryEvent $event) {
            $event->dbQuery->andWhere(['not', ['elementId' => self::$fixture->ids['ferry']]]);
        });

        $this->assertNotContains('Ferry timetable', $this->titles('quokka'));
    }

    public function testResultRowsEventCanAddCustomRows(): void
    {
        $this->on(Search::class, Search::EVENT_MODIFY_RESULT_ROWS, function(RowsEvent $event) {
            array_unshift($event->rows, ['type' => 'custom', 'title' => 'Contact', 'url' => '/contact']);
        });

        $search = $this->search('quokka', ['resultsPerPage' => 2]);
        $this->assertSame(4, $search['total']);
        $this->assertSame('Contact', $search['results'][0]['title']);
        $this->assertCount(2, $search['results']);
    }

    public function testResultsEvent(): void
    {
        $expected = array_reverse($this->titles('quokka'));

        $this->on(Search::class, Search::EVENT_MODIFY_RESULTS, function(RowsEvent $event) {
            $event->rows = array_reverse($event->rows);
        });

        $this->assertSame($expected, $this->titles('quokka'));
    }

    public function testWeightScoreEvents(): void
    {
        $this->on(Sort::class, Sort::EVENT_ADD_WEIGHT_SCORE, function(WeightScoreEvent $event) {
            if ($event->elementRow['elementId'] === self::$fixture->ids['englishOnly']) {
                $event->score += 100000;
            }
        });

        $this->assertSame('Wombat burrows', $this->titles('quokka')[0]);
    }

    public function testRowScoreEvent(): void
    {
        $this->on(Sort::class, Sort::EVENT_ADD_WEIGHT_ROW_SCORE, function(WeightScoreEvent $event) {
            if ($event->row['attribute'] === 'field' && str_contains($event->row['normalizedWords'], 'cyclists')) {
                $event->score += 100000;
            }
        });

        $this->assertSame('Ferry timetable', $this->titles('quokka')[0]);
    }

    public function testBeforeAndAfterSearchEvents(): void
    {
        $events = [];
        $this->on(Search::class, Search::EVENT_BEFORE_SEARCH, function(SearchEvent $event) use (&$events) {
            $events[] = ['before', $event->normalizedQuery, $event->dbQuery !== null];
        });
        $this->on(Search::class, Search::EVENT_AFTER_SEARCH, function(SearchEvent $event) use (&$events) {
            $events[] = ['after', $event->total, count($event->results)];
        });

        $this->search('Quokka!');

        $this->assertSame([['before', 'quokka', true], ['after', 3, 3]], $events);
    }

    public function testRenderResults(): void
    {
        $search = $this->search('quokka', ['resultsPerPage' => 2]);
        $html = preg_replace('/\s+/', ' ', $this->plugin()->search->renderResults($search, 'quokka'));

        $this->assertStringContainsString('3 results found for &quot;quokka&quot;', $html);
        $this->assertStringContainsString('<a href="' . $search['results'][0]['url'] . '" class="title">', $html);
        // "…" is attached where the text was cut, extracts are separated by " … "
        $this->assertStringContainsString('<p>How rangers protect the <mark>quokka</mark> habitat. … is home to the <mark>quokka</mark>.', $html);
        $this->assertStringNotContainsString('<p> ', $html);
        $this->assertMatchesRegularExpression('/<a class="nav" data-page="2" href="[^"]+">/', $html);

        $variableHtml = (string)(new \oncode\rawsearch\variables\RawSearchVariable())->renderResults($search, 'quokka');
        $this->assertSame($this->plugin()->search->renderResults($search, 'quokka'), $variableHtml);

        $this->assertStringContainsString('No results found.', $this->plugin()->search->renderResults($this->search('nothingatall'), 'nothingatall'));
    }

    public function testCountResults(): void
    {
        foreach (['quokka', 'eucalyptus rangers', 'cyclists xylophonist', 'nothing'] as $query) {
            $this->assertSame($this->search($query)['total'], $this->plugin()->search->countResults(['query' => $query]), $query);
        }

        $this->assertSame(2, $this->plugin()->search->countResults(['query' => 'cyclists xylophonist', 'or' => true]));
        $this->assertSame(0, $this->plugin()->search->countResults(['query' => '?!']));
    }

    public function testStatistic(): void
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $devMode = $general->devMode;
        $count = fn() => (int)(new Query())->from(Table::QUERIES)->where(['query' => 'rottnest island'])->count();
        $before = $count();

        try {
            $general->devMode = false;

            $this->plugin()->search->search(['query' => 'Rottnest Island']);
            $this->assertSame($before + 1, $count());

            $this->plugin()->search->search(['query' => 'Rottnest Island', 'statistic' => false]);
            $this->setSettings(['statistic' => false]);
            $this->plugin()->search->search(['query' => 'Rottnest Island']);
            $this->assertSame($before + 1, $count());

            $general->devMode = true;
            $this->setSettings(['statistic' => true]);
            $this->plugin()->search->search(['query' => 'Rottnest Island']);
            $this->assertSame($before + 1, $count());

            $row = (new Query())->from(Table::QUERIES)->where(['query' => 'rottnest island'])->orderBy(['id' => SORT_DESC])->one();
            $this->assertSame(1, (int)$row['results']);
            $this->assertSame(Search::MODE_WORD_START, (int)$row['mode']);

            $top = $this->plugin()->queries->getMostSearched(self::$fixture->primarySite->id, 100);
            $this->assertContains('rottnest island', array_column($top, 'query'));

            // only the first page is counted
            $general->devMode = false;
            $quokkaCount = fn() => (int)(new Query())->from(Table::QUERIES)->where(['query' => 'quokka'])->count();
            $quokkaBefore = $quokkaCount();
            $this->plugin()->search->search(['query' => 'quokka', 'page' => 2, 'resultsPerPage' => 1]);
            $this->assertSame($quokkaBefore, $quokkaCount());
        } finally {
            $general->devMode = $devMode;
            Craft::$app->getDb()->createCommand()->delete(Table::QUERIES, ['query' => ['rottnest island', 'quokka']])->execute();
        }
    }
}
