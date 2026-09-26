<?php

namespace oncode\rawsearch\records;

use craft\db\ActiveRecord;
use oncode\rawsearch\db\Table;

/**
 * @property int $id
 * @property string $type
 * @property bool $index
 * @property int|null $matchWeight
 */
class ElementTypeConfig extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ELEMENT_TYPE_CONFIGS;
    }
}
