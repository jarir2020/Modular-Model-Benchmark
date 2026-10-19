<?php

// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Illuminate\Support\Facades\Route;
use Modules\Roles\Controllers\RolesController;

Route::prefix('roles')
    ->name('roles.')
    ->controller(RolesController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{role}', 'update')->name('update');
        Route::delete('/{role}', 'destroy')->name('destroy');
        // Assignment rides nested under the role it belongs to: the pair
        // (role, membership) is what gets created and removed.
        Route::post('/{role}/members', 'assign')->name('assign');
        Route::delete('/{role}/members/{teamMember}', 'unassign')->name('unassign');
    });
