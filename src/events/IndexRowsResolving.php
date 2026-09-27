<?php

namespace oncode\rawsearch\events;

/**
 * Allows modifying the rows that get inserted into the index table (`$event->rows`).
 */
class IndexRowsResolving extends IndexElementEvent
{
}
