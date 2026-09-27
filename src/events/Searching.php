<?php

namespace oncode\rawsearch\events;

/**
 * Fired before the index gets searched. The `dbQuery` can still be modified.
 */
class Searching extends SearchEvent
{
}
