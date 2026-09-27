<?php

namespace oncode\rawsearch\tests\integration;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Query;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\events\AttributeValuesResolving;
use oncode\rawsearch\events\ElementIndexing;
use oncode\rawsearch\events\FieldValuesResolving;
use oncode\rawsearch\events\IndexRowsResolving;
use oncode\rawsearch\jobs\IndexElements;
use oncode\rawsearch\jobs\IndexElementType;
use oncode\rawsearch\tests\ContentFixture;
use oncode\rawsearch\tests\TestCase;

class IndexTest extends TestCase
{
    private function attributes(array $rows): array
    {
        $attributes = array_map(fn($row) => $row['attribute'] . ($row['fieldId'] ? ':' . Fields::getFieldById((int)$row['fieldId'])->handle : ''), $rows);
        sort($attributes);

        return $attributes;
    }

    private function normalizedWords(int $elementId, ?int $siteId = null): string
    {
        return implode('|', array_column($this->indexRows($elementId, $siteId ?? self::$fixture->primarySite->id), 'normalizedWords'));
    }

    public function testRowsForAttributesFieldsAndNestedFields(): void
    {
        $rows = $this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id);

        $this->assertSame([
            'field:rsBlockText',
            'field:rsBlockText',
            'field:rsBody',
            'field:rsIntro',
            'field:rsSecret',
            'slug',
            'title',
        ], $this->attributes($rows));

        $title = array_values(array_filter($rows, fn($row) => $row['attribute'] === 'title'))[0];
        $this->assertSame(' quokka habitat protection ', $title['normalizedWords']);
        $this->assertSame('Quokka habitat protection', $title['text']);
        $this->assertSame(Entry::class, $title['type']);
    }

    public function testEverySiteHasItsOwnContent(): void
    {
        $this->assertStringContainsString('rottnest', $this->normalizedWords(self::$fixture->ids['quokka']));
        $de = $this->normalizedWords(self::$fixture->ids['quokka'], self::$fixture->deSite->id);
        $this->assertStringContainsString(' muller ', $de);
        $this->assertStringContainsString(' grusse ', $de);
        $this->assertStringNotContainsString('rottnest', $de);
    }

    public function testTextKeepsPunctuationAndDecodesHtml(): void
    {
        $rows = $this->indexRows(self::$fixture->ids['eucalyptus'], self::$fixture->primarySite->id);
        $body = array_values(array_filter($rows, fn($row) => str_contains($row['text'], 'planted 47')))[0];

        $this->assertSame('In 2024 we planted 47 trees. Planting works & it pays off. <script>alert(1)</script>', $body['text']);
        $this->assertSame(' in 2024 we planted 47 trees planting works it pays off script alert 1 script ', $body['normalizedWords']);
    }

    public function testNestedEntriesAreNotIndexedOnTheirOwn(): void
    {
        $blockIds = self::$fixture->entry('quokka')->getFieldValue('rsBlocks')->ids();

        $this->assertCount(2, $blockIds);
        $this->assertSame(0, DB::table(Table::INDEX)->whereIn('elementId', $blockIds)->count());
    }

    public function testDisabledEntriesAreNotIndexed(): void
    {
        $this->assertSame([], $this->indexRows(self::$fixture->ids['disabled']));
        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['englishOnly'], self::$fixture->primarySite->id));
        $this->assertSame([], $this->indexRows(self::$fixture->ids['englishOnly'], self::$fixture->deSite->id));
    }

    public function testScheduledAndExpiredEntriesAreIndexed(): void
    {
        // they are filtered when searching, so they show up as soon as they are live
        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['future']));
        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['expired']));
    }

    public function testSavingUpdatesTheIndex(): void
    {
        $entry = self::$fixture->entry('ferry');
        $entry->setFieldValue('rsBody', 'Now with a catamaran.');
        Elements::saveElement($entry);
        ContentFixture::runQueue();

        $words = $this->normalizedWords($entry->id);
        $this->assertStringContainsString('catamaran', $words);
        $this->assertStringNotContainsString('cyclists', $words);
        // the other site is untouched
        $this->assertStringContainsString('velofahrer', $this->normalizedWords($entry->id, self::$fixture->deSite->id));
    }

    public function testDisablingAndEnabling(): void
    {
        $entry = self::$fixture->entry('ferry');
        $entry->enabled = false;
        Elements::saveElement($entry);
        ContentFixture::runQueue();
        $this->assertSame([], $this->indexRows($entry->id));

        $entry->enabled = true;
        Elements::saveElement($entry);
        ContentFixture::runQueue();
        $this->assertNotEmpty($this->indexRows($entry->id, self::$fixture->primarySite->id));
        $this->assertNotEmpty($this->indexRows($entry->id, self::$fixture->deSite->id));
    }

    public function testDeletingAndRestoring(): void
    {
        $entry = self::$fixture->entry('ferry');
        Elements::deleteElement($entry);
        $this->assertSame([], $this->indexRows($entry->id));

        Elements::restoreElement($entry);
        ContentFixture::runQueue();
        $this->assertNotEmpty($this->indexRows($entry->id));
    }

    public function testSavingNestedEntryReindexesOwner(): void
    {
        $owner = self::$fixture->entry('quokka');
        $block = $owner->getFieldValue('rsBlocks')->one();
        $block->setFieldValue('rsBlockText', 'Brand new block about kiwifruit.');
        Elements::saveElement($block);
        ContentFixture::runQueue();

        $words = $this->normalizedWords($owner->id);
        $this->assertStringContainsString('kiwifruit', $words);
        $this->assertStringNotContainsString('eucalyptus', $words);
    }

    public function testDeletingNestedEntryReindexesOwner(): void
    {
        $owner = self::$fixture->entry('eucalyptus');
        $block = $owner->getFieldValue('rsBlocks')->one();
        Elements::deleteElement($block);
        ContentFixture::runQueue();

        $this->assertStringNotContainsString('shade', $this->normalizedWords($owner->id));
    }

    public function testDraftsDontChangeTheIndex(): void
    {
        $entry = self::$fixture->entry('quokka');
        $draft = Drafts::createDraft($entry, 1);
        $draft->setFieldValue('rsIntro', 'Draft content about platypus.');
        Elements::saveElement($draft);
        ContentFixture::runQueue();

        $this->assertStringNotContainsString('platypus', $this->normalizedWords($entry->id));
        $this->assertSame([], $this->indexRows($draft->id));

        Elements::deleteElement($draft, true);
    }

    public function testBlacklistedFieldsAreNotIndexed(): void
    {
        $secret = Fields::getFieldByHandle('rsSecret');
        $this->plugin()->fieldConfigs->saveIndex($secret->id, false);
        $this->plugin()->index->indexElement(self::$fixture->entry('quokka'));

        $this->assertStringNotContainsString('xylophonist', $this->normalizedWords(self::$fixture->ids['quokka']));
    }

    public function testBlacklistedFieldInsideMatrixIsNotIndexed(): void
    {
        $blockText = Fields::getFieldByHandle('rsBlockText');
        $this->plugin()->fieldConfigs->saveIndex($blockText->id, false);
        $this->plugin()->index->indexElement(self::$fixture->entry('quokka'));

        $this->assertNotContains('field:rsBlockText', $this->attributes($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id)));
    }

    public function testOnlyWhitelistedFieldTypesAreIndexed(): void
    {
        $this->setSettings(['whitelistedFieldTypes' => []]);
        $this->plugin()->index->indexElement(self::$fixture->entry('quokka'));

        $this->assertSame(['slug', 'title'], $this->attributes($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id)));
    }

    public function testMatrixNeedsToBeWhitelistedToIndexItsFields(): void
    {
        $this->setSettings(['whitelistedFieldTypes' => [PlainText::class]]);
        $this->plugin()->index->indexElement(self::$fixture->entry('quokka'));

        $this->assertNotContains('field:rsBlockText', $this->attributes($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id)));
    }

    public function testBlacklistedWords(): void
    {
        $this->setSettings(['blacklistedWords' => 'the, rangers']);
        $this->plugin()->index->indexElement(self::$fixture->entry('quokka'));

        $rows = $this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id);
        $intro = array_values(array_filter($rows, fn($row) => str_starts_with($row['text'], 'How rangers')))[0];

        $this->assertSame(' how protect quokka habitat ', $intro['normalizedWords']);
        // snippets still show the original text
        $this->assertSame('How rangers protect the quokka habitat.', $intro['text']);
    }

    public function testBlacklistedElementTypes(): void
    {
        $this->plugin()->elementTypeConfigs->saveIndex(Entry::class, false);
        $entry = self::$fixture->entry('ferry');

        $this->assertFalse($this->plugin()->index->indexElement($entry));
        $this->assertSame([], $this->indexRows($entry->id, self::$fixture->primarySite->id));
        $this->assertNotContains(Entry::class, $this->plugin()->index->getIndexedElementTypes());

        $this->plugin()->elementTypeConfigs->saveIndex(Entry::class, true);
        $this->assertTrue($this->plugin()->index->indexElement($entry));
    }

    public function testRemoveNotIndexedElementTypes(): void
    {
        $this->plugin()->elementTypeConfigs->saveIndex(Entry::class, false);
        $this->plugin()->index->removeNotIndexedElementTypes();

        $this->assertSame(0, DB::table(Table::INDEX)->where('type', Entry::class)->count());

        $this->plugin()->elementTypeConfigs->saveIndex(Entry::class, true);
        $this->reindexEntries();
    }

    public function testBeforeIndexEventCanPreventIndexing(): void
    {
        $this->on(ElementIndexing::class, function(ElementIndexing $event) {
            $event->isValid = $event->element->id !== self::$fixture->ids['ferry'];
        });

        $this->assertFalse($this->plugin()->index->indexElement(self::$fixture->entry('ferry')));
        $this->assertSame([], $this->indexRows(self::$fixture->ids['ferry'], self::$fixture->primarySite->id));
        $this->assertTrue($this->plugin()->index->indexElement(self::$fixture->entry('quokka')));
    }

    public function testModifyEvents(): void
    {
        $this->on(AttributeValuesResolving::class, function(AttributeValuesResolving $event) {
            $event->attributeValues['title'] .= ' boat';
        });
        $this->on(FieldValuesResolving::class, function(FieldValuesResolving $event) {
            $event->fieldValues = array_values(array_filter($event->fieldValues, fn($value) => $value['handle'] !== 'rsBody'));
        });
        $this->on(IndexRowsResolving::class, function(IndexRowsResolving $event) {
            foreach ($event->rows as &$row) {
                $row['normalizedWords'] .= 'extraword ';
            }
        });

        $this->plugin()->index->indexElement(self::$fixture->entry('ferry'));
        $rows = $this->indexRows(self::$fixture->ids['ferry'], self::$fixture->primarySite->id);

        $this->assertContains('Ferry timetable boat', array_column($rows, 'text'));
        $this->assertNotContains('field:rsBody', $this->attributes($rows));
        $this->assertStringEndsWith(' extraword ', $rows[0]['normalizedWords']);
    }

    public function testIndexElementTypeRemovesStaleRows(): void
    {
        $entry = self::$fixture->entry('ferry');
        Elements::deleteElement($entry);

        // simulate a row that was left behind
        DB::table(Table::INDEX)->insert([
            'elementId' => $entry->id,
            'siteId' => self::$fixture->primarySite->id,
            'type' => Entry::class,
            'attribute' => 'title',
            'normalizedWords' => ' stale ',
            'text' => 'stale',
            'dateIndexed' => Query::prepareDateForDb(new \DateTime('-1 hour')),
        ]);

        $this->reindexEntries();
        $this->assertSame([], $this->indexRows($entry->id));

        Elements::restoreElement($entry);
        ContentFixture::runQueue();
    }

    public function testIndexElementTypeJob(): void
    {
        DB::table(Table::INDEX)->where('elementId', self::$fixture->ids['quokka'])->delete();

        // the tests run with the sync queue
        dispatch(new IndexElementType(Entry::class));

        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id));
        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->deSite->id));
    }

    public function testSavingEntryTypeQueuesReindex(): void
    {
        // Craft only fires the event when something changed
        $entryType = self::$fixture->pageType;
        $name = $entryType->name;
        $entryType->name = $name . ' (changed)';

        $bus = Bus::getFacadeRoot();
        Bus::fake([IndexElements::class]);

        try {
            EntryTypes::saveEntryType($entryType);

            Bus::assertDispatched(IndexElements::class, fn(IndexElements $job) => in_array(self::$fixture->ids['quokka'], $job->elementIds, true));
        } finally {
            Bus::swap($bus);
            $entryType->name = $name;
            EntryTypes::saveEntryType($entryType);
            ContentFixture::runQueue();
        }
    }
}
