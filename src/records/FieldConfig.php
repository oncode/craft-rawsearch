<?php

namespace oncode\rawsearch\records;

use craft\db\ActiveRecord;
use oncode\rawsearch\db\Table;

/**
 * @property int $id
 * @property int $fieldId
 * @property bool $index
 * @property int|null $matchWeight
 * @property int|null $partialMatchWeight
 */
class FieldConfig extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::FIELD_CONFIGS;
    }
}
