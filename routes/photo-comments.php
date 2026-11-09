<?php

use App\Http\Controllers\PhotoCommentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('photo-posts/{photoPost}/comments', [PhotoCommentController::class, 'index'])
        ->whereNumber('photoPost')->middleware('throttle:60,1')->name('photo-comments.index');
    Route::post('photo-posts/{photoPost}/comments', [PhotoCommentController::class, 'store'])
        ->whereNumber('photoPost')->middleware('throttle:60,1')->name('photo-comments.store');
    Route::delete('photo-comments/{comment}', [PhotoCommentController::class, 'destroy'])
        ->whereNumber('comment')->middleware('throttle:60,1')->name('photo-comments.destroy');
});
