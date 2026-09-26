<?php

namespace oncode\rawsearch\events;

use yii\base\Event;

/**
 * Allows adding points to the weight score of an element or an index row.
 * Add points with `$event->score += 100`.
 */
class WeightScoreEvent extends Event
{
    /** The grouped element row (`elementId`, `type`, `rows`). */
    public array $elementRow = [];

    /** The index row, only set for the row score event. */
    public ?array $row = null;

    /** Score calculated so far, modify it to change the score. */
    public int $score = 0;

    public string $normalizedQuery = '';
}
