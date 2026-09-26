<?php

namespace oncode\rawsearch\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use oncode\rawsearch\RawSearch;
use yii\console\ExitCode;

/**
 * Builds the RawSearch index.
 */
class IndexController extends Controller
{
    /**
     * @var bool Whether to push queue jobs instead of indexing right away.
     */
    public bool $queue = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue']);
    }

    /**
     * Rebuilds the index of all indexed element types.
     */
    public function actionAll(): int
    {
        $index = RawSearch::getInstance()->index;

        if ($this->queue) {
            $index->queueAll();
            $this->stdout("Jobs pushed to the queue.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $index->removeNotIndexedElementTypes();

        foreach ($index->getIndexedElementTypes() as $elementType) {
            $this->indexElementType($elementType);
        }

        return ExitCode::OK;
    }

    /**
     * Rebuilds the index of the given element types (comma separated, e.g. `entry,asset`).
     */
    public function actionElementTypes(string $elementTypes): int
    {
        $plugin = RawSearch::getInstance();

        try {
            $types = $plugin->search->resolveElementTypes($elementTypes) ?? [];
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        foreach ($types as $type) {
            if (!$plugin->elementTypeConfigs->isIndexed($type)) {
                $this->stdout("Skipping $type, it's not indexed.\n", Console::FG_YELLOW);
                continue;
            }

            if ($this->queue) {
                $plugin->index->queueElementType($type);
            } else {
                $this->indexElementType($type);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Reindexes elements by id (comma separated).
     */
    public function actionElements(string $elementIds): int
    {
        $index = RawSearch::getInstance()->index;
        $elements = \Craft::$app->getElements();

        foreach (array_filter(array_map('intval', explode(',', $elementIds))) as $id) {
            $element = $elements->getElementById($id, null, '*');

            if ($element) {
                $index->queueElement($element);
            } else {
                $this->stdout("Element $id not found.\n", Console::FG_YELLOW);
            }
        }

        $index->pushQueuedElements();

        if (!$this->queue) {
            \Craft::$app->getQueue()->run();
        }

        $this->stdout("Done.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function indexElementType(string $elementType): void
    {
        $this->stdout("Indexing $elementType ...\n");

        $count = RawSearch::getInstance()->index->indexElementType($elementType, function(int $done, int $total) {
            if ($done === 1) {
                Console::startProgress(0, $total);
            }

            Console::updateProgress($done, $total);
        });

        if ($count > 0) {
            Console::endProgress();
        }

        $this->stdout("Processed $count elements.\n", Console::FG_GREEN);
    }
}
