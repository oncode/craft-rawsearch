<?php

namespace oncode\rawsearch\events;

use CraftCms\Cms\Twig\Variables\Paginate;
use Illuminate\Database\Query\Builder;

/**
 * Base class of the events fired before/after a search or autocomplete search.
 */
abstract class SearchEvent
{
    /**
     * @param string $query The query as entered by the user
     * @param string $normalizedQuery The normalized query that is searched for
     * @param array $config The resolved search config
     * @param Builder|null $dbQuery The db query that searches the index (only modify it in "before" events)
     * @param array $results Search results (after search only)
     * @param array $words Autocomplete words (after autocomplete only)
     */
    public function __construct(
        public string $query = '',
        public string $normalizedQuery = '',
        public array $config = [],
        public ?Builder $dbQuery = null,
        public array $results = [],
        public ?Paginate $pagination = null,
        public int $total = 0,
        public array $words = [],
    ) {
    }
}
