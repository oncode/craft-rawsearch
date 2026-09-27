<?php

namespace oncode\rawsearch\console;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Console\Command;
use oncode\rawsearch\RawSearch;

class IndexElementsCommand extends Command
{
    use CraftCommand;

    protected $signature = 'rawsearch:index:elements
        {elementIds : Comma separated element ids}
        {--queue : Push queue jobs instead of indexing right away}';

    protected $description = 'Reindexes RawSearch elements by id.';

    protected $aliases = ['rawsearch/index/elements'];

    public function handle(): int
    {
        $index = RawSearch::getInstance()->index;

        foreach (array_filter(array_map('intval', explode(',', $this->argument('elementIds')))) as $id) {
            $element = Elements::getElementById($id, null, '*');

            if ($element) {
                $index->queueElement($element);
            } else {
                $this->components->warn("Element $id not found.");
            }
        }

        $index->pushQueuedElements(!$this->option('queue'));
        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
