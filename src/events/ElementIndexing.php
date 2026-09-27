<?php

namespace oncode\rawsearch\events;

/**
 * Fired before an element gets indexed, set `$event->isValid = false` to skip it.
 */
class ElementIndexing extends IndexElementEvent
{
}
