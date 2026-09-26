<?php

namespace oncode\rawsearch\events;

use yii\base\Event;

/**
 * Allows modifying a list of rows (result rows, results or autocomplete words).
 * Change `$event->rows` to modify them.
 */
class RowsEvent extends Event
{
    public array $rows = [];

    public string $normalizedQuery = '';

    public array $config = [];
}
