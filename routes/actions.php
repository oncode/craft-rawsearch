<?php

// Actions, available as `actions/rawsearch/<path>` (site and control panel).
// The paths match the Craft 5 action ids, so forms posting `action=rawsearch/...` keep working.

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use oncode\rawsearch\controllers\ApiController;
use oncode\rawsearch\controllers\SettingsController;
use oncode\rawsearch\controllers\StatisticController;
use oncode\rawsearch\RawSearch;

// public API
Route::match(['get', 'post'], 'api/search', [ApiController::class, 'search']);
Route::match(['get', 'post'], 'api/autocomplete', [ApiController::class, 'autocomplete']);

// API key or logged in user with permission, the controller checks the CSRF token of session requests
Route::withoutMiddleware([PreventRequestForgery::class])->group(function() {
    Route::match(['get', 'post'], 'api/queries', [ApiController::class, 'queries']);
    Route::match(['get', 'post'], 'api/reindex-elements', [ApiController::class, 'reindexElements']);
    Route::match(['get', 'post'], 'api/reindex-element-types', [ApiController::class, 'reindexElementTypes']);
});

Route::middleware(['auth', 'can:accessCp'])->group(function() {
    Route::post('statistic/clear', [StatisticController::class, 'clear'])
        ->middleware('can:' . RawSearch::PERMISSION_ACCESS_STATISTIC);

    Route::middleware('can:' . RawSearch::PERMISSION_EDIT_SETTINGS)->prefix('settings')->group(function() {
        Route::post('save-general', [SettingsController::class, 'saveGeneral'])->middleware(RequireAdmin::class);
        Route::post('save-settings', [SettingsController::class, 'saveSettings']);

        Route::middleware('can:' . RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS)->group(function() {
            Route::post('save-element-type-weights', [SettingsController::class, 'saveElementTypeWeights']);
            Route::post('save-field-weights', [SettingsController::class, 'saveFieldWeights']);
        });

        Route::middleware('can:' . RawSearch::PERMISSION_EDIT_INDEX_SETTINGS)->group(function() {
            Route::post('save-field-types', [SettingsController::class, 'saveFieldTypes']);
            Route::post('save-field-indexes', [SettingsController::class, 'saveFieldIndexes']);
            Route::post('save-element-type-indexes', [SettingsController::class, 'saveElementTypeIndexes']);
            Route::post('reindex', [SettingsController::class, 'reindex']);
        });
    });
});
