<?php

namespace oncode\rawsearch\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use oncode\rawsearch\RawSearch;
use yii\console\ExitCode;

/**
 * Manages the API key.
 */
class ApiKeyController extends Controller
{
    /**
     * Generates a new API key and prints it.
     */
    public function actionGenerate(): int
    {
        $plugin = RawSearch::getInstance();

        if (!$plugin->generateApiKey()) {
            $this->stderr("Could not save the API key.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout($plugin->getSettings()->apiKey . "\n");

        return ExitCode::OK;
    }
}
