<?php

namespace oncode\rawsearch\tests;

use Craft;
use craft\db\Query;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\RawSearch;
use PHPUnit\Framework\TestCase as BaseTestCase;
use yii\base\Event;

/**
 * Base class for tests that need the fixture content.
 * Settings, element type/field configs and event handlers are restored after every test.
 */
abstract class TestCase extends BaseTestCase
{
    protected static ContentFixture $fixture;

    private array $settingsBackup = [];
    private array $configBackup = [];
    private array $eventHandlers = [];

    public static function setUpBeforeClass(): void
    {
        self::$fixture = ContentFixture::get();
        self::$fixture->reset();
    }

    protected function setUp(): void
    {
        $this->settingsBackup = $this->plugin()->getSettings()->getAttributes();

        foreach ([Table::ELEMENT_TYPE_CONFIGS, Table::FIELD_CONFIGS] as $table) {
            $this->configBackup[$table] = (new Query())->from($table)->all();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->eventHandlers as [$class, $name, $handler]) {
            Event::off($class, $name, $handler);
        }

        $this->eventHandlers = [];
        $this->plugin()->getSettings()->setAttributes($this->settingsBackup, false);

        $db = Craft::$app->getDb();

        foreach ($this->configBackup as $table => $rows) {
            $db->createCommand()->delete($table)->execute();

            if ($rows) {
                $db->createCommand()->batchInsert($table, array_keys($rows[0]), array_map('array_values', $rows))->execute();
            }
        }

        // the config services cache their records
        Craft::$app->getPlugins()->getPlugin('rawsearch')->set('elementTypeConfigs', \oncode\rawsearch\services\ElementTypeConfigs::class);
        Craft::$app->getPlugins()->getPlugin('rawsearch')->set('fieldConfigs', \oncode\rawsearch\services\FieldConfigs::class);
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
        $this->plugin()->getSettings()->setAttributes($values, false);
    }

    /**
     * Registers an event handler for the current test only.
     */
    protected function on(string $class, string $name, callable $handler): void
    {
        Event::on($class, $name, $handler);
        $this->eventHandlers[] = [$class, $name, $handler];
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
        $this->plugin()->index->indexElementType(\craft\elements\Entry::class);
    }

    protected function indexRows(int $elementId, ?int $siteId = null): array
    {
        return (new Query())
            ->from(Table::INDEX)
            ->where(['elementId' => $elementId])
            ->andFilterWhere(['siteId' => $siteId])
            ->all();
    }
}
