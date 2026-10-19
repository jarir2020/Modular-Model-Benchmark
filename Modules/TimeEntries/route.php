<?php

// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Modules\TimeEntries\Controllers\TimeEntriesController;
use Illuminate\Support\Facades\Route;

Route::prefix('time-entries')
    ->name('time-entries.')
    ->controller(TimeEntriesController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{timeEntry}', 'update')->name('update');
        Route::delete('/{timeEntry}', 'destroy')->name('destroy');
    });
