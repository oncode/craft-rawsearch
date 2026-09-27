<?php

namespace oncode\rawsearch\events;

/**
 * Allows modifying the data of an element related to an autocomplete word.
 */
class WordElementDataResolving
{
    /**
     * @param array $elementData Element data (`id`, `type`, `count`), modify it to add more data
     * @param array $row The index row the word was found in (includes columns added via AutocompleteQueryResolving)
     */
    public function __construct(
        public array $elementData = [],
        public array $row = [],
    ) {
    }
}
