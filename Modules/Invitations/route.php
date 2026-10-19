<?php

use Modules\Invitations\Controllers\InvitationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('invitations')
    ->name('invitations.')
    ->controller(InvitationsController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/{invitation}/accept', 'accept')->name('accept');
        Route::post('/{invitation}/revoke', 'revoke')->name('revoke');
        Route::delete('/{invitation}', 'destroy')->name('destroy');
    });