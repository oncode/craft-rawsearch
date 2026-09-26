<?php

// Boots the Craft console application of the installation the tests run against.

$basePath = getenv('CRAFT_BASE_PATH');

if (!$basePath || !is_file($basePath . '/bootstrap.php')) {
    fwrite(STDERR, "Set CRAFT_BASE_PATH to a Craft installation that has RawSearch installed.\n");
    exit(1);
}

require $basePath . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

// The Craft bootstrap hides deprecations and Yii turns errors into exceptions.
// PHPUnit handles them instead and reports only the ones caused by the plugin (see <source>).
error_reporting(E_ALL);
ini_set('display_errors', '0');
restore_error_handler();

if (!Craft::$app->getPlugins()->isPluginInstalled('rawsearch')) {
    fwrite(STDERR, "RawSearch is not installed in $basePath.\n");
    exit(1);
}

require __DIR__ . '/_support/ContentFixture.php';
require __DIR__ . '/_support/TestCase.php';
