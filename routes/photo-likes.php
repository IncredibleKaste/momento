<?php

use App\Http\Controllers\PhotoLikeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('photo-posts/{photoPost}/like', [PhotoLikeController::class, 'store'])
        ->whereNumber('photoPost')
        ->middleware('throttle:60,1')
        ->name('photo-likes.store');
});
