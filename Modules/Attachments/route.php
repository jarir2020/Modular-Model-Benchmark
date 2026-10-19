<?php

use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::resource('attachments', AttachmentsController::class);
});
