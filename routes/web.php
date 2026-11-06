<?php

use App\Http\Controllers\Auth\MicrosoftAuthenticationController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\MemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('following', [FollowController::class, 'index'])->name('following.index');
    Route::get('members/{member}', [MemberController::class, 'show'])->whereNumber('member')->name('members.show');
    Route::post('members/{member}/follow-requests', [FollowController::class, 'store'])
        ->whereNumber('member')->middleware('throttle:follow-requests')->name('follows.store');
    Route::patch('follow-requests/{follow}', [FollowController::class, 'approve'])
        ->whereNumber('follow')->middleware('throttle:follow-actions')->name('follows.approve');
    Route::delete('follow-requests/{follow}', [FollowController::class, 'destroy'])
        ->whereNumber('follow')->middleware('throttle:follow-actions')->name('follows.destroy');
});

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('auth/microsoft', [MicrosoftAuthenticationController::class, 'index'])->name('microsoft.index');
    Route::post('auth/microsoft/organizations/{organization}', [MicrosoftAuthenticationController::class, 'start'])->name('microsoft.start');
    Route::get('auth/microsoft/callback', [MicrosoftAuthenticationController::class, 'callback'])->name('microsoft.callback');
    Route::get('auth/microsoft/contact', [MicrosoftAuthenticationController::class, 'contact'])->name('microsoft.contact');
    Route::post('auth/microsoft/contact', [MicrosoftAuthenticationController::class, 'storeContact'])->name('microsoft.contact.store');
    Route::get('auth/microsoft/contact/verify/{proof}', [MicrosoftAuthenticationController::class, 'verifyContact'])
        ->middleware('signed')->name('microsoft.contact.verify');
    Route::post('auth/microsoft/contact/verify/{proof}', [MicrosoftAuthenticationController::class, 'completeContact'])
        ->middleware('signed')->name('microsoft.contact.complete');
});

require __DIR__.'/settings.php';

Route::fallback(function (Request $request): Response {
    if ($request->expectsJson()) {
        abort(404);
    }

    return Inertia::render('not-found')->toResponse($request)->setStatusCode(404);
});
