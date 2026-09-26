<?php

namespace oncode\rawsearch\events;

use craft\elements\db\ElementQueryInterface;
use yii\base\Event;

/**
 * Allows modifying the element query that is used to fetch the found elements.
 * Elements that are not returned by the query are removed from the results.
 */
class ElementQueryEvent extends Event
{
    /** @var class-string */
    public string $elementType;

    public ElementQueryInterface $query;

    public array $config = [];
}
