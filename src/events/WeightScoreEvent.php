<?php

namespace oncode\rawsearch\events;

/**
 * Base class of the events that allow adding points to the weight score of an element or an index row.
 * Add points with `$event->score += 100`.
 */
abstract class WeightScoreEvent
{
    /**
     * @param array $elementRow The grouped element row (`elementId`, `type`, `rows`)
     * @param array|null $row The index row, only set for RowScoreResolving
     * @param int $score Score calculated so far, modify it to change the score
     */
    public function __construct(
        public array $elementRow = [],
        public ?array $row = null,
        public int $score = 0,
        public string $normalizedQuery = '',
    ) {
    }
}
