<?php

namespace oncode\rawsearch\tests;

use CraftCms\Cms\Entry\Elements\Entry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\RawSearch;
use oncode\rawsearch\services\ElementTypeConfigs;
use oncode\rawsearch\services\FieldConfigs;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class for tests that need the fixture content.
 * Settings, element type/field configs and event listeners are restored after every test.
 */
abstract class TestCase extends BaseTestCase
{
    protected static ContentFixture $fixture;

    private array $settingsBackup = [];
    private array $configBackup = [];

    /** @var string[] Event classes with listeners of the current test */
    private array $events = [];

    public static function setUpBeforeClass(): void
    {
        self::$fixture = ContentFixture::get();
        self::$fixture->reset();
    }

    protected function setUp(): void
    {
        $this->settingsBackup = $this->plugin()->getSettings()->validationData();

        foreach ([Table::ELEMENT_TYPE_CONFIGS, Table::FIELD_CONFIGS] as $table) {
            $this->configBackup[$table] = self::rows(DB::table($table)->get());
        }
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->events) as $event) {
            Event::forget($event);
        }

        $this->events = [];
        $this->plugin()->getSettings()->setAttributes($this->settingsBackup);

        foreach ($this->configBackup as $table => $rows) {
            DB::table($table)->delete();

            if ($rows) {
                DB::table($table)->insert($rows);
            }
        }

        // the config services cache their records
        app()->forgetInstance(ElementTypeConfigs::class);
        app()->forgetInstance(FieldConfigs::class);
    }

    protected function plugin(): RawSearch
    {
        return RawSearch::getInstance();
    }

    /**
     * Changes settings for the current test only.
     */
    protected function setSettings(array $values): void
    {
        $this->plugin()->getSettings()->setAttributes($values);
    }

    /**
     * Registers an event listener for the current test only.
     * RawSearch has no other listeners of its own events, so all of the event's listeners are removed afterwards.
     *
     * @param class-string $event
     */
    protected function on(string $event, callable $listener): void
    {
        Event::listen($event, $listener);
        $this->events[] = $event;
    }

    protected function search(string $query, array $params = []): array
    {
        return $this->plugin()->search->search(['query' => $query, 'statistic' => false] + $params);
    }

    /**
     * @return string[] Titles of the found results
     */
    protected function titles(string $query, array $params = []): array
    {
        return array_map(fn($result) => $result['title'] ?? '?', $this->search($query, $params)['results']);
    }

    /**
     * Rebuilds the index of all entries.
     */
    protected function reindexEntries(): void
    {
        $this->plugin()->index->indexElementType(Entry::class);
    }

    protected function indexRows(int $elementId, ?int $siteId = null): array
    {
        return self::rows(DB::table(Table::INDEX)
            ->where('elementId', $elementId)
            ->when($siteId, fn($query) => $query->where('siteId', $siteId))
            ->get());
    }

    /**
     * Converts db rows (objects) to arrays.
     */
    protected static function rows(iterable $rows): array
    {
        $arrays = [];

        foreach ($rows as $row) {
            $arrays[] = (array)$row;
        }

        return $arrays;
    }
}
