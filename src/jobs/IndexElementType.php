<?php

namespace oncode\rawsearch\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\db\QueryBatcher;
use craft\helpers\Db;
use craft\queue\BaseBatchedJob;
use oncode\rawsearch\RawSearch;

/**
 * Reindexes all elements of an element type in batches.
 */
class IndexElementType extends BaseBatchedJob
{
    /** @var class-string<ElementInterface> */
    public string $elementType;

    /** When the first batch started, rows indexed before are stale at the end. */
    public ?string $startedAt = null;

    protected function loadData(): Batchable
    {
        return new QueryBatcher(RawSearch::getInstance()->index->createElementTypeQuery($this->elementType));
    }

    protected function before(): void
    {
        $this->startedAt = Db::prepareDateForDb(new \DateTime());
    }

    protected function beforeBatch(): void
    {
        RawSearch::getInstance()->index->morePowerPls();
    }

    protected function processItem(mixed $item): void
    {
        RawSearch::getInstance()->index->indexElement($item);
    }

    protected function after(): void
    {
        if ($this->startedAt) {
            RawSearch::getInstance()->index->removeStaleRows($this->elementType, $this->startedAt);
        }
    }

    protected function defaultDescription(): ?string
    {
        $name = class_exists($this->elementType) ? $this->elementType::pluralDisplayName() : $this->elementType;

        return Craft::t('rawsearch', 'Updating search index of {type}', ['type' => $name]);
    }
}
