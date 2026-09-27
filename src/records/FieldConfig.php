<?php

namespace oncode\rawsearch\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use oncode\rawsearch\db\Table;

/**
 * @property int $id
 * @property int $fieldId
 * @property bool $index
 * @property int|null $matchWeight
 * @property int|null $partialMatchWeight
 */
class FieldConfig extends BaseModel
{
    use HasUid;

    protected $table = Table::FIELD_CONFIGS;

    protected $casts = [
        'fieldId' => 'integer',
        'index' => 'boolean',
        'matchWeight' => 'integer',
        'partialMatchWeight' => 'integer',
    ];
}
