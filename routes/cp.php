<?php

// Control panel pages. Craft adds no auth to plugin routes, so it's done here.

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;
use oncode\rawsearch\controllers\SettingsController;
use oncode\rawsearch\controllers\StatisticController;
use oncode\rawsearch\RawSearch;

Route::middleware(['auth', 'can:accessCp', 'can:accessPlugin-rawsearch'])->prefix('rawsearch')->group(function() {
    Route::get('/', [StatisticController::class, 'index'])->middleware('can:' . RawSearch::PERMISSION_ACCESS_STATISTIC);

    Route::middleware('can:' . RawSearch::PERMISSION_ACCESS_STATISTIC)->prefix('statistic')->group(function() {
        Route::get('/', [StatisticController::class, 'index']);
        Route::get('queries', [StatisticController::class, 'queries']);
        Route::get('top', [StatisticController::class, 'top']);
    });

    Route::middleware('can:' . RawSearch::PERMISSION_EDIT_SETTINGS)->prefix('settings')->group(function() {
        Route::get('/', [SettingsController::class, 'index']);
        Route::get('general', [SettingsController::class, 'general'])->middleware(RequireAdmin::class);

        Route::middleware('can:' . RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS)->group(function() {
            Route::get('weight', [SettingsController::class, 'weightGeneral']);
            Route::get('weight/general', [SettingsController::class, 'weightGeneral']);
            Route::get('weight/element-types', [SettingsController::class, 'weightElementTypes']);
            Route::get('weight/fields', [SettingsController::class, 'weightFields']);
        });

        Route::middleware('can:' . RawSearch::PERMISSION_EDIT_INDEX_SETTINGS)->group(function() {
            Route::get('indexing', [SettingsController::class, 'indexingWords']);
            Route::get('indexing/words', [SettingsController::class, 'indexingWords']);
            Route::get('indexing/field-types', [SettingsController::class, 'indexingFieldTypes']);
            Route::get('indexing/fields', [SettingsController::class, 'indexingFields']);
            Route::get('indexing/element-types', [SettingsController::class, 'indexingElementTypes']);
        });
    });
});
