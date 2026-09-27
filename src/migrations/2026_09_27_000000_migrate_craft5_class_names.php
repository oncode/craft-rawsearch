<?php

use CraftCms\Cms\Address\Elements\Address;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Elements\ContentBlock;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Field\Table as TableField;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use oncode\rawsearch\db\Table;

/**
 * Craft 6 renamed the element and field classes. Core migrates its own tables,
 * this does the same for the class names RawSearch stores.
 */
return new class extends Migration
{
    /** @var array<string, class-string> */
    private array $elementTypes = [
        'craft\elements\Address' => Address::class,
        'craft\elements\Asset' => Asset::class,
        'craft\elements\ContentBlock' => ContentBlock::class,
        'craft\elements\Entry' => Entry::class,
        'craft\elements\User' => User::class,
    ];

    /** @var array<string, class-string> */
    private array $fieldTypes = [
        'craft\fields\Matrix' => Matrix::class,
        'craft\fields\PlainText' => PlainText::class,
        'craft\fields\Table' => TableField::class,
    ];

    public function up(): void
    {
        foreach ($this->elementTypes as $old => $new) {
            DB::table(Table::INDEX)->where('type', $old)->update(['type' => $new]);

            // the new class may have a config already (e.g. created by the installation defaults)
            if (DB::table(Table::ELEMENT_TYPE_CONFIGS)->where('type', $new)->exists()) {
                DB::table(Table::ELEMENT_TYPE_CONFIGS)->where('type', $old)->delete();
            } else {
                DB::table(Table::ELEMENT_TYPE_CONFIGS)->where('type', $old)->update(['type' => $new]);
            }
        }

        $projectConfig = app(ProjectConfig::class);
        $path = ProjectConfig::PATH_PLUGINS . '.rawsearch.settings.whitelistedFieldTypes';
        $fieldTypes = $projectConfig->get($path);

        if (is_array($fieldTypes)) {
            $renamed = array_map(fn($type) => $this->fieldTypes[$type] ?? $type, $fieldTypes);

            if ($renamed !== $fieldTypes) {
                $projectConfig->set($path, $renamed, 'Update RawSearch field types for Craft 6');
            }
        }
    }

    public function down(): void
    {
        foreach ($this->elementTypes as $old => $new) {
            DB::table(Table::INDEX)->where('type', $new)->update(['type' => $old]);
            DB::table(Table::ELEMENT_TYPE_CONFIGS)->where('type', $new)->update(['type' => $old]);
        }
    }
};
