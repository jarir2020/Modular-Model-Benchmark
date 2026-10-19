<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Task Dependencies module, roadmap #9.
// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Illuminate\Support\Facades\Route;
use Modules\TaskDependencies\Controllers\TaskDependenciesController;

Route::prefix('task-dependencies')
    ->name('task-dependencies.')
    ->controller(TaskDependenciesController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        // A link is a single fact (two tasks, one direction), so there is no
        // update route: re-linking means delete and re-create.
        Route::delete('/{taskDependency}', 'destroy')->name('destroy');
    });
