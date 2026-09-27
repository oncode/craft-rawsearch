<?php

namespace oncode\rawsearch\events;

/**
 * Base class of the events that allow modifying a list of rows (result rows, results or autocomplete words).
 * Change `$event->rows` to modify them.
 */
abstract class RowsEvent
{
    public function __construct(
        public array $rows = [],
        public string $normalizedQuery = '',
        public array $config = [],
    ) {
    }
}
