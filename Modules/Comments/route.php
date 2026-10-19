<?php

// Implemented with model: kr/qwen3-coder-next (Qwen 3 Coder Next) — Comments module, roadmap #5.
// Discovered by ModulesServiceProvider, which applies `web` middleware.

use Illuminate\Support\Facades\Route;
use Modules\Comments\Controllers\CommentController;

Route::prefix('comments')
    ->name('comments.')
    ->controller(CommentController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{comment}', 'update')->name('update');
        Route::delete('/{comment}', 'destroy')->name('destroy');
    });
