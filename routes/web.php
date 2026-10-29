<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

Route::fallback(function (Request $request): Response {
    if ($request->expectsJson()) {
        abort(404);
    }

    return Inertia::render('not-found')->toResponse($request)->setStatusCode(404);
});
