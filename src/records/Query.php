<?php

namespace oncode\rawsearch\records;

use craft\db\ActiveRecord;
use oncode\rawsearch\db\Table;

/**
 * A search query stored for the statistic.
 *
 * @property int $id
 * @property int $siteId
 * @property string $query
 * @property bool $or
 * @property int $mode
 * @property int $results
 * @property \DateTime $dateCreated
 */
class Query extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::QUERIES;
    }
}
