<?php

namespace oncode\rawsearch\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
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
 * @property \DateTimeInterface $dateCreated
 */
class Query extends BaseModel
{
    use HasUid;

    protected $table = Table::QUERIES;

    protected $casts = [
        'siteId' => 'integer',
        'or' => 'boolean',
        'mode' => 'integer',
        'results' => 'integer',
    ];
}
