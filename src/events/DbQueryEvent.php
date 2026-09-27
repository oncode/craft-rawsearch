<?php

namespace oncode\rawsearch\events;

use Illuminate\Database\Query\Builder;

/**
 * Base class of the events that allow modifying the db query that searches the index table, e.g. to join other tables.
 */
abstract class DbQueryEvent
{
    public function __construct(
        public Builder $dbQuery,
        public string $normalizedQuery = '',
        public array $config = [],
    ) {
    }
}
