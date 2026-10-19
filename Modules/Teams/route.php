<?php

// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Modules\Teams\Controllers\TeamsController;
use Illuminate\Support\Facades\Route;

Route::prefix('teams')
    ->name('teams.')
    ->controller(TeamsController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{team}', 'update')->name('update');
        Route::delete('/{team}', 'destroy')->name('destroy');
    });