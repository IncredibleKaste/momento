<?php

use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailUpdatesUnsubscribeController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PeopleSearchController;
use App\Http\Controllers\PhotoPostController;
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
    Route::get('photo-posts/create', [PhotoPostController::class, 'create'])->name('photo-posts.create');
    Route::post('photo-posts', [PhotoPostController::class, 'store'])->middleware('throttle:photo-posts')->name('photo-posts.store');
    Route::get('photo-posts/{photoPost}/media', [PhotoPostController::class, 'media'])->whereNumber('photoPost')->name('photo-posts.media');
    Route::post('photo-posts/{photoPost}/cancel', [PhotoPostController::class, 'cancel'])->whereNumber('photoPost')->name('photo-posts.cancel');
    Route::get('photo-posts/{photoPost}', [PhotoPostController::class, 'show'])->whereNumber('photoPost')->name('photo-posts.show');
    Route::get('people', PeopleSearchController::class)->middleware('throttle:people-search')->name('people.index');
    Route::get('following', [FollowController::class, 'index'])->name('following.index');
    Route::get('messages', [ConversationController::class, 'index'])->name('messages.index');
    Route::get('messages/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation')->name('messages.show');
    Route::post('members/{member}/messages', [ConversationController::class, 'store'])->whereNumber('member')->name('messages.store');
    Route::post('messages/{conversation}/send', [ConversationController::class, 'send'])->whereNumber('conversation')->middleware('throttle:message-send')->name('messages.send');
    Route::post('messages/{conversation}/read/{message}', [ConversationController::class, 'read'])
        ->whereNumber('conversation')->whereNumber('message')->name('messages.read');
    Route::get('members/{member}', [MemberController::class, 'show'])->whereNumber('member')->name('members.show');
    Route::post('members/{member}/follow-requests', [FollowController::class, 'store'])
        ->whereNumber('member')->middleware('throttle:follow-requests')->name('follows.store');
    Route::patch('follow-requests/{follow}', [FollowController::class, 'approve'])
        ->whereNumber('follow')->middleware('throttle:follow-actions')->name('follows.approve');
    Route::delete('follow-requests/{follow}', [FollowController::class, 'destroy'])
        ->whereNumber('follow')->middleware('throttle:follow-actions')->name('follows.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/photo-likes.php';
require __DIR__.'/photo-saves.php';
require __DIR__.'/photo-comments.php';

Route::fallback(function (Request $request): Response {
    if ($request->expectsJson()) {
        abort(404);
    }

    return Inertia::render('not-found')->toResponse($request)->setStatusCode(404);
});
