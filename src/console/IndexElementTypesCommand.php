<?php

namespace oncode\rawsearch\console;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use oncode\rawsearch\RawSearch;

class IndexElementTypesCommand extends Command
{
    use CraftCommand;
    use IndexesElementTypes;

    protected $signature = 'rawsearch:index:element-types
        {elementTypes : Comma separated element types, e.g. `entry,asset`}
        {--queue : Push queue jobs instead of indexing right away}';

    protected $description = 'Rebuilds the RawSearch index of the given element types.';

    protected $aliases = ['rawsearch/index/element-types'];

    public function handle(): int
    {
        $plugin = RawSearch::getInstance();

        try {
            $types = $plugin->search->resolveElementTypes($this->argument('elementTypes')) ?? [];
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::INVALID;
        }

        foreach ($types as $type) {
            if (!$plugin->elementTypeConfigs->isIndexed($type)) {
                $this->components->warn("Skipping $type, it's not indexed.");
                continue;
            }

            if ($this->option('queue')) {
                $plugin->index->queueElementType($type);
            } else {
                $this->indexElementType($type);
            }
        }

        return self::SUCCESS;
    }
}
