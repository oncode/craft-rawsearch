<?php

namespace oncode\rawsearch\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use oncode\rawsearch\RawSearch;

/**
 * Reindexes the given elements in all their sites.
 */
class IndexElements extends BaseJob
{
    /** @var class-string<ElementInterface> */
    public string $elementType;

    /** @var int[] */
    public array $elementIds = [];

    public function execute($queue): void
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
            $this->setProgress($queue, ($i + 1) / $total);
            $index->indexElement($element);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('rawsearch', 'Updating search index of {count, plural, =1{# element} other{# elements}}', [
            'count' => count($this->elementIds),
        ]);
    }
}
