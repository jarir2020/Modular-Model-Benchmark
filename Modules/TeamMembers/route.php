<?php

// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Modules\TeamMembers\Controllers\TeamMembersController;
use Illuminate\Support\Facades\Route;

Route::prefix('team-members')
    ->name('team-members.')
    ->controller(TeamMembersController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{teamMember}', 'update')->name('update');
        Route::delete('/{teamMember}', 'destroy')->name('destroy');
    });
