<?php

namespace oncode\rawsearch\console;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use oncode\rawsearch\RawSearch;

class ApiKeyGenerateCommand extends Command
{
    use CraftCommand;

    protected $signature = 'rawsearch:api-key:generate';

    protected $description = 'Generates a new RawSearch API key and prints it.';

    protected $aliases = ['rawsearch/api-key/generate'];

    public function handle(): int
    {
        $plugin = RawSearch::getInstance();

        if (!$plugin->generateApiKey()) {
            $this->components->error('Could not save the API key.');
            return self::FAILURE;
        }

        $this->line($plugin->getSettings()->apiKey);

        return self::SUCCESS;
    }
}
