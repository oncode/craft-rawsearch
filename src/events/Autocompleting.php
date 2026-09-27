<?php

namespace oncode\rawsearch\events;

/**
 * Fired before the index gets searched for autocomplete words. The `dbQuery` can still be modified.
 */
class Autocompleting extends SearchEvent
{
}
