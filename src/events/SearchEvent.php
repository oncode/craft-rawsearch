<?php

namespace oncode\rawsearch\events;

use craft\db\Query;
use craft\web\twig\variables\Paginate;
use yii\base\Event;

/**
 * Fired before/after a search or autocomplete search.
 */
class SearchEvent extends Event
{
    /** The query as entered by the user. */
    public string $query = '';

    /** The normalized query that is searched for. */
    public string $normalizedQuery = '';

    /** The resolved search config. */
    public array $config = [];

    /** The db query that searches the index (only modify it in "before" events). */
    public ?Query $dbQuery = null;

    /** Search results (after events only). */
    public array $results = [];

    public ?Paginate $pagination = null;

    public int $total = 0;

    /** Autocomplete words (autocomplete after event only). */
    public array $words = [];
}
