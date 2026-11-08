<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailUpdatesUnsubscribeController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PeopleSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

Route::inertia('/', 'welcome')->name('home');

Route::get('email-updates/unsubscribe/{user}', [EmailUpdatesUnsubscribeController::class, 'confirm'])
    ->whereNumber('user')->middleware('signed')->name('email-updates.unsubscribe.confirm');
Route::post('email-updates/unsubscribe/{user}', [EmailUpdatesUnsubscribeController::class, 'unsubscribe'])
    ->whereNumber('user')->middleware('signed')->name('email-updates.unsubscribe');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('people', PeopleSearchController::class)->middleware('throttle:people-search')->name('people.index');
    Route::get('following', [FollowController::class, 'index'])->name('following.index');
    Route::get('members/{member}', [MemberController::class, 'show'])->whereNumber('member')->name('members.show');
    Route::post('members/{member}/follow-requests', [FollowController::class, 'store'])
        ->whereNumber('member')->middleware('throttle:follow-requests')->name('follows.store');
    Route::patch('follow-requests/{follow}', [FollowController::class, 'approve'])
        ->whereNumber('follow')->middleware('throttle:follow-actions')->name('follows.approve');
    Route::delete('follow-requests/{follow}', [FollowController::class, 'destroy'])
        ->whereNumber('follow')->middleware('throttle:follow-actions')->name('follows.destroy');
});

require __DIR__.'/settings.php';

Route::fallback(function (Request $request): Response {
    if ($request->expectsJson()) {
        abort(404);
    }

    return Inertia::render('not-found')->toResponse($request)->setStatusCode(404);
});
