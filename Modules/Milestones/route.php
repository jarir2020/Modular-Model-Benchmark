<?php

use Illuminate\Support\Facades\Route;
use Modules\Milestones\Controllers\MilestonesController;

Route::prefix('milestones')
    ->name('milestones.')
    ->controller(MilestonesController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{milestone}', 'update')->name('update');
        Route::delete('/{milestone}', 'destroy')->name('destroy');
    });
