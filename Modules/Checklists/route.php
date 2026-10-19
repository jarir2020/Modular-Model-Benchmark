<?php

use Illuminate\Support\Facades\Route;
use Modules\Checklists\Controllers\ChecklistsController;

// Discovered by ModulesServiceProvider, which applies `web` middleware.

Route::prefix('checklists')
    ->name('checklists.')
    ->controller(ChecklistsController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{checklist}', 'update')->name('update');
        Route::delete('/{checklist}', 'destroy')->name('destroy');
        Route::post('/reorder', 'reorder')->name('reorder');
    });
