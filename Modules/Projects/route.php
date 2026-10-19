<?php

use Illuminate\Support\Facades\Route;
use Modules\Projects\Controllers\ProjectController;

Route::prefix('projects')
    ->name('projects.')
    ->controller(ProjectController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{project}', 'update')->name('update');
        Route::delete('/{project}', 'destroy')->name('destroy');
    });
