<?php

namespace oncode\rawsearch\jobs;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Queue\Job;
use CraftCms\Cms\Support\Facades\I18N;
use oncode\rawsearch\RawSearch;

/**
 * Reindexes the given elements in all their sites.
 */
class IndexElements extends Job
{
    /**
     * @param class-string<ElementInterface> $elementType
     * @param int[] $elementIds
     */
    public function __construct(
        public string $elementType,
        public array $elementIds = [],
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $index = RawSearch::getInstance()->index;
        $index->morePowerPls();

        if (!class_exists($this->elementType) || empty($this->elementIds)) {
            return;
        }

        $index->removeByElementIds($this->elementIds);

        $elements = $index->createElementTypeQuery($this->elementType)
            ->id($this->elementIds)
            ->all();
        $total = count($elements);

        foreach ($elements as $i => $element) {
            $this->setProgress((int)(($i + 1) / $total * 100));
            $index->indexElement($element);
        }
    }

    protected function defaultDescription(): string
    {
        return I18N::prep('Updating search index of {count, plural, =1{# element} other{# elements}}', [
            'count' => count($this->elementIds),
        ], 'rawsearch');
    }
}
