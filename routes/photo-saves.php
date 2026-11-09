<?php

use App\Http\Controllers\PhotoSaveController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('saved', [PhotoSaveController::class, 'index'])->name('saved.index');
    Route::post('saved/{photoPost}', [PhotoSaveController::class, 'update'])
        ->middleware('throttle:60,1,photo-saves')
        ->whereNumber('photoPost')->name('photo-saves.update');
});
