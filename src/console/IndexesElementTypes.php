<?php

namespace oncode\rawsearch\console;

use Illuminate\Console\Command;
use oncode\rawsearch\RawSearch;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * @mixin Command
 */
trait IndexesElementTypes
{
    private function indexElementType(string $elementType): void
    {
        $this->line("Indexing $elementType ...");
        $bar = null;

        $count = RawSearch::getInstance()->index->indexElementType($elementType, function(int $done, int $total) use (&$bar) {
            $bar ??= $this->output->createProgressBar($total);
            $bar->setProgress($done);
        });

        if ($bar instanceof ProgressBar) {
            $bar->finish();
            $this->newLine();
        }

        $this->components->info("Processed $count elements.");
    }
}
