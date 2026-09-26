<?php

namespace oncode\rawsearch\events;

use craft\db\Query;
use yii\base\Event;

/**
 * Allows modifying the db query that searches the index table, e.g. to join other tables.
 */
class DbQueryEvent extends Event
{
    public Query $dbQuery;

    public string $normalizedQuery = '';

    public array $config = [];
}
