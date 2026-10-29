<?php

use App\Http\Controllers\Auth\MicrosoftAuthenticationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('auth/microsoft', [MicrosoftAuthenticationController::class, 'index'])->name('microsoft.index');
    Route::post('auth/microsoft/organizations/{organization}', [MicrosoftAuthenticationController::class, 'start'])->name('microsoft.start');
    Route::get('auth/microsoft/callback', [MicrosoftAuthenticationController::class, 'callback'])->name('microsoft.callback');
    Route::get('auth/microsoft/contact', [MicrosoftAuthenticationController::class, 'contact'])->name('microsoft.contact');
    Route::post('auth/microsoft/contact', [MicrosoftAuthenticationController::class, 'storeContact'])->name('microsoft.contact.store');
});

require __DIR__.'/settings.php';

Route::fallback(function (Request $request): Response {
    if ($request->expectsJson()) {
        abort(404);
    }

    return Inertia::render('not-found')->toResponse($request)->setStatusCode(404);
});
