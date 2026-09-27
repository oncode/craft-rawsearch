<?php

namespace oncode\rawsearch\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\helpers\Queue;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\events\IndexElementEvent;
use oncode\rawsearch\jobs\IndexElementType;
use oncode\rawsearch\services\Index;
use oncode\rawsearch\tests\ContentFixture;
use oncode\rawsearch\tests\TestCase;

class IndexTest extends TestCase
{
    private function attributes(array $rows): array
    {
        $attributes = array_map(fn($row) => $row['attribute'] . ($row['fieldId'] ? ':' . Craft::$app->getFields()->getFieldById($row['fieldId'])->handle : ''), $rows);
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
        $this->assertSame(0, (int)(new Query())->from(Table::INDEX)->where(['elementId' => $blockIds])->count());
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
        Craft::$app->getElements()->saveElement($entry);
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
        Craft::$app->getElements()->saveElement($entry);
        ContentFixture::runQueue();
        $this->assertSame([], $this->indexRows($entry->id));

        $entry->enabled = true;
        Craft::$app->getElements()->saveElement($entry);
        ContentFixture::runQueue();
        $this->assertNotEmpty($this->indexRows($entry->id, self::$fixture->primarySite->id));
        $this->assertNotEmpty($this->indexRows($entry->id, self::$fixture->deSite->id));
    }

    public function testDeletingAndRestoring(): void
    {
        $entry = self::$fixture->entry('ferry');
        Craft::$app->getElements()->deleteElement($entry);
        $this->assertSame([], $this->indexRows($entry->id));

        Craft::$app->getElements()->restoreElement($entry);
        ContentFixture::runQueue();
        $this->assertNotEmpty($this->indexRows($entry->id));
    }

    public function testSavingNestedEntryReindexesOwner(): void
    {
        $owner = self::$fixture->entry('quokka');
        $block = $owner->getFieldValue('rsBlocks')->one();
        $block->setFieldValue('rsBlockText', 'Brand new block about kiwifruit.');
        Craft::$app->getElements()->saveElement($block);
        ContentFixture::runQueue();

        $words = $this->normalizedWords($owner->id);
        $this->assertStringContainsString('kiwifruit', $words);
        $this->assertStringNotContainsString('eucalyptus', $words);
    }

    public function testDeletingNestedEntryReindexesOwner(): void
    {
        $owner = self::$fixture->entry('eucalyptus');
        $block = $owner->getFieldValue('rsBlocks')->one();
        Craft::$app->getElements()->deleteElement($block);
        ContentFixture::runQueue();

        $this->assertStringNotContainsString('shade', $this->normalizedWords($owner->id));
    }

    public function testDraftsDontChangeTheIndex(): void
    {
        $entry = self::$fixture->entry('quokka');
        $draft = Craft::$app->getDrafts()->createDraft($entry, 1);
        $draft->setFieldValue('rsIntro', 'Draft content about platypus.');
        Craft::$app->getElements()->saveElement($draft);
        ContentFixture::runQueue();

        $this->assertStringNotContainsString('platypus', $this->normalizedWords($entry->id));
        $this->assertSame([], $this->indexRows($draft->id));

        Craft::$app->getElements()->deleteElement($draft, true);
    }

    public function testBlacklistedFieldsAreNotIndexed(): void
    {
        $secret = Craft::$app->getFields()->getFieldByHandle('rsSecret');
        $this->plugin()->fieldConfigs->saveIndex($secret->id, false);
        $this->plugin()->index->indexElement(self::$fixture->entry('quokka'));

        $this->assertStringNotContainsString('xylophonist', $this->normalizedWords(self::$fixture->ids['quokka']));
    }

    public function testBlacklistedFieldInsideMatrixIsNotIndexed(): void
    {
        $blockText = Craft::$app->getFields()->getFieldByHandle('rsBlockText');
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

        $this->assertSame(0, (int)(new Query())->from(Table::INDEX)->where(['type' => Entry::class])->count());

        $this->plugin()->elementTypeConfigs->saveIndex(Entry::class, true);
        $this->reindexEntries();
    }

    public function testBeforeIndexEventCanPreventIndexing(): void
    {
        $this->on(Index::class, Index::EVENT_BEFORE_INDEX_ELEMENT, function(IndexElementEvent $event) {
            $event->isValid = $event->element->id !== self::$fixture->ids['ferry'];
        });

        $this->assertFalse($this->plugin()->index->indexElement(self::$fixture->entry('ferry')));
        $this->assertSame([], $this->indexRows(self::$fixture->ids['ferry'], self::$fixture->primarySite->id));
        $this->assertTrue($this->plugin()->index->indexElement(self::$fixture->entry('quokka')));
    }

    public function testModifyEvents(): void
    {
        $this->on(Index::class, Index::EVENT_MODIFY_ATTRIBUTE_VALUES, function(IndexElementEvent $event) {
            $event->attributeValues['title'] .= ' boat';
        });
        $this->on(Index::class, Index::EVENT_MODIFY_FIELD_VALUES, function(IndexElementEvent $event) {
            $event->fieldValues = array_values(array_filter($event->fieldValues, fn($value) => $value['handle'] !== 'rsBody'));
        });
        $this->on(Index::class, Index::EVENT_MODIFY_ROWS, function(IndexElementEvent $event) {
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
        Craft::$app->getElements()->deleteElement($entry);

        // simulate a row that was left behind
        Db::insert(Table::INDEX, [
            'elementId' => $entry->id,
            'siteId' => self::$fixture->primarySite->id,
            'type' => Entry::class,
            'attribute' => 'title',
            'normalizedWords' => ' stale ',
            'text' => 'stale',
            'dateIndexed' => Db::prepareDateForDb(new \DateTime('-1 hour')),
        ]);

        $this->reindexEntries();
        $this->assertSame([], $this->indexRows($entry->id));

        Craft::$app->getElements()->restoreElement($entry);
        ContentFixture::runQueue();
    }

    public function testIndexElementTypeJob(): void
    {
        Db::delete(Table::INDEX, ['elementId' => self::$fixture->ids['quokka']]);

        Queue::push(new IndexElementType(['elementType' => Entry::class]));
        Craft::$app->getQueue()->run();

        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->primarySite->id));
        $this->assertNotEmpty($this->indexRows(self::$fixture->ids['quokka'], self::$fixture->deSite->id));
    }

    public function testSavingEntryTypeQueuesReindex(): void
    {
        // Craft only fires the event when something changed
        $entryType = self::$fixture->pageType;
        $name = $entryType->name;
        $entryType->name = $name . ' (changed)';

        try {
            Craft::$app->getEntries()->saveEntryType($entryType);
            $this->plugin()->index->pushQueuedElements();

            $jobs = (new Query())->select(['description'])->from('{{%queue}}')->column();
            Craft::$app->getQueue()->run();

            $this->assertNotEmpty(array_filter($jobs, fn($description) => str_contains((string)$description, 'Updating search index')));
        } finally {
            $entryType->name = $name;
            Craft::$app->getEntries()->saveEntryType($entryType);
            Craft::$app->getQueue()->run();
        }
    }
}
