<?php

// Boots the Laravel app of the Craft 6 installation the tests run against.

$basePath = getenv('CRAFT_BASE_PATH');

if (!$basePath || !is_file($basePath . '/bootstrap/app.php')) {
    fwrite(STDERR, "Set CRAFT_BASE_PATH to a Craft 6 installation that has RawSearch installed.\n");
    exit(1);
}

require $basePath . '/vendor/autoload.php';

// jobs run right away, the tests check their result
putenv('QUEUE_CONNECTION=sync');
$_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = 'sync';
$_ENV['APP_BASE_PATH'] = $basePath;

/** @var \Illuminate\Foundation\Application $app */
$app = require $basePath . '/bootstrap/app.php';
// the console kernel of Craft, like the `craft` binary uses
$app->singleton(\Illuminate\Contracts\Console\Kernel::class, \CraftCms\Cms\Console\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// saves the project config and releases its lock, like at the end of a request
register_shutdown_function(fn() => $app->terminate());

// Laravel turns errors into exceptions, PHPUnit handles them instead
// and reports only the ones caused by the plugin (see <source>).
error_reporting(E_ALL);
ini_set('display_errors', '0');
restore_error_handler();
restore_exception_handler();

if (!\CraftCms\Cms\Support\Facades\Plugins::isPluginInstalled('rawsearch')) {
    fwrite(STDERR, "RawSearch is not installed in $basePath.\n");
    exit(1);
}

require __DIR__ . '/_support/ContentFixture.php';
require __DIR__ . '/_support/TestCase.php';
