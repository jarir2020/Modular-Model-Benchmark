<?php

// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Illuminate\Support\Facades\Route;
use Modules\ProjectMembers\Controllers\ProjectMembersController;

Route::prefix('project-members')
    ->name('project-members.')
    ->controller(ProjectMembersController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{projectMember}', 'update')->name('update');
        Route::delete('/{projectMember}', 'destroy')->name('destroy');
    });
