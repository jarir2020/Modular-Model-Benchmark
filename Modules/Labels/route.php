<?php

// Implemented with model: zm/atria-asi/atria-dawn-preview (Atria Dawn Preview,
// running as Claude Code) — Labels module, roadmap #4.
// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Illuminate\Support\Facades\Route;
use Modules\Labels\Controllers\LabelController;

Route::prefix('labels')
    ->name('labels.')
    ->controller(LabelController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{label}', 'update')->name('update');
        Route::delete('/{label}', 'destroy')->name('destroy');

        // Label attachment lives under the task the labels belong to. The
        // task segment is scoped to a numeric id so `labels/{label}` cannot
        // capture the literal "task" as a label id.
        Route::prefix('/task')
            ->controller(LabelController::class)
            ->group(function (): void {
                Route::post('/{task}/attach', 'attach')->name('attach');
                Route::post('/{task}/detach', 'detach')->name('detach');
                Route::post('/{task}/sync', 'sync')->name('sync');
            });
    });
