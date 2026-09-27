<?php

namespace oncode\rawsearch\events;

/**
 * Allows modifying the autocomplete db query, e.g. to join more data for WordElementDataResolving.
 */
class AutocompleteQueryResolving extends DbQueryEvent
{
}
