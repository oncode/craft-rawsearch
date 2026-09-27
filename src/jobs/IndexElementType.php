<?php

namespace oncode\rawsearch\jobs;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Queue\BatchedJob;
use CraftCms\Cms\Support\Facades\I18N;
use CraftCms\Cms\Support\Query;
use Illuminate\Contracts\Database\Query\Builder;
use oncode\rawsearch\RawSearch;

/**
 * Reindexes all elements of an element type in batches.
 */
class IndexElementType extends BatchedJob
{
    /** When the first batch started, rows indexed before are stale at the end. */
    public ?string $startedAt = null;

    /**
     * @param class-string<ElementInterface> $elementType
     */
    public function __construct(
        public string $elementType,
    ) {
        parent::__construct();
    }

    protected function getQuery(): Builder
    {
        return RawSearch::getInstance()->index->createElementTypeQuery($this->elementType);
    }

    protected function before(): void
    {
        $this->startedAt = Query::prepareDateForDb(new \DateTime());
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

    protected function defaultDescription(): string
    {
        $name = class_exists($this->elementType) ? $this->elementType::pluralDisplayName() : $this->elementType;

        return I18N::prep('Updating search index of {type}', ['type' => $name], 'rawsearch');
    }
}
