<?php

namespace oncode\rawsearch\events;

use yii\base\Event;

/**
 * Allows modifying the data of an element related to an autocomplete word.
 */
class AutocompleteWordElementEvent extends Event
{
    /** Element data (`id`, `type`, `count`), modify it to add more data. */
    public array $elementData = [];

    /** The index row the word was found in (includes columns added via the query event). */
    public array $row = [];
}
