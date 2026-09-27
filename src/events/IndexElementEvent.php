<?php

namespace oncode\rawsearch\events;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Shared\Concerns\ValidatableEvent;

/**
 * Base class of the events fired while an element gets indexed.
 * Set `$event->isValid = false` in ElementIndexing to prevent the element from being indexed.
 */
abstract class IndexElementEvent
{
    use ValidatableEvent;

    /**
     * @param array<string,string> $attributeValues Attribute name => value
     * @param array $fieldValues Field values (`id`, `type`, `handle`, `value`)
     * @param array $rows Rows that will be inserted into the index table
     */
    public function __construct(
        public ElementInterface $element,
        public array $attributeValues = [],
        public array $fieldValues = [],
        public array $rows = [],
    ) {
    }
}
