<?php

namespace oncode\rawsearch\events;

use craft\base\ElementInterface;
use craft\events\CancelableEvent;

/**
 * Fired while an element gets indexed.
 * Set `$event->isValid = false` in the "before" event to prevent the element from being indexed.
 */
class IndexElementEvent extends CancelableEvent
{
    public ElementInterface $element;

    /** @var array<string,string> Attribute name => value */
    public array $attributeValues = [];

    /** @var array Field values (`id`, `type`, `handle`, `value`) */
    public array $fieldValues = [];

    /** @var array Rows that will be inserted into the index table */
    public array $rows = [];
}
