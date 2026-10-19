<?php

// Implemented with model: agnes/agnes-3.0-pro (Agnes 3.0 Pro) — Tasks module, roadmap #2.
// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Illuminate\Support\Facades\Route;
use Modules\Tasks\Controllers\TaskController;

Route::prefix('tasks')
    ->name('tasks.')
    ->controller(TaskController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{task}', 'update')->name('update');
        Route::delete('/{task}', 'destroy')->name('destroy');
    });
