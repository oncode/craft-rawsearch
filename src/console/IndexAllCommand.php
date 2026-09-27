<?php

namespace oncode\rawsearch\console;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use oncode\rawsearch\RawSearch;

class IndexAllCommand extends Command
{
    use CraftCommand;
    use IndexesElementTypes;

    protected $signature = 'rawsearch:index:all {--queue : Push queue jobs instead of indexing right away}';

    protected $description = 'Rebuilds the RawSearch index of all indexed element types.';

    protected $aliases = ['rawsearch/index/all'];

    public function handle(): int
    {
        $index = RawSearch::getInstance()->index;

        if ($this->option('queue')) {
            $index->queueAll();
            $this->components->info('Jobs pushed to the queue.');
            return self::SUCCESS;
        }

        $index->removeNotIndexedElementTypes();

        foreach ($index->getIndexedElementTypes() as $elementType) {
            $this->indexElementType($elementType);
        }

        return self::SUCCESS;
    }
}
