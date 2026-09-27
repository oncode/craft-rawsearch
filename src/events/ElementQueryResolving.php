<?php

namespace oncode\rawsearch\events;

use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;

/**
 * Allows modifying the element query that is used to fetch the found elements (per element type).
 * Elements that are not returned by the query are removed from the results.
 */
class ElementQueryResolving
{
    /**
     * @param class-string $elementType
     */
    public function __construct(
        public string $elementType,
        public ElementQueryInterface $query,
        public array $config = [],
    ) {
    }
}
