<?php

namespace oncode\rawsearch\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use oncode\rawsearch\db\Table;

/**
 * @property int $id
 * @property string $type
 * @property bool $index
 * @property int|null $matchWeight
 */
class ElementTypeConfig extends BaseModel
{
    use HasUid;

    protected $table = Table::ELEMENT_TYPE_CONFIGS;

    protected $casts = [
        'index' => 'boolean',
        'matchWeight' => 'integer',
    ];
}
