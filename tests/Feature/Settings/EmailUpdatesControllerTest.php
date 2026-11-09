<?php

use App\Models\User;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the owner their saved default-off product news preference', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner->fresh())->get(route('email-updates.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/email-updates')
            ->where('auth.user.id', $owner->id)
            ->where('productNewsOptIn', false));

    expect($owner->fresh()->product_news_opt_in)->toBeFalse();
});

it('saves only the signed-in account product news preference', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->patch(route('email-updates.update'), [
        'product_news_opt_in' => true,
        'user_id' => $other->id,
        'email' => $other->email,
    ])->assertRedirectToRoute('email-updates.edit')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Email preference saved.');

    expect($owner->fresh()->product_news_opt_in)->toBeTrue()
        ->and($other->fresh()->product_news_opt_in)->toBeFalse();
});

it('keeps the saved preference when the submitted value is invalid', function (mixed $value) {
    $owner = User::factory()->create(['product_news_opt_in' => true]);

    $this->actingAs($owner)->from(route('email-updates.edit'))
        ->patch(route('email-updates.update'), ['product_news_opt_in' => $value])
        ->assertRedirectToRoute('email-updates.edit')
        ->assertSessionHasErrors('product_news_opt_in')
        ->assertInertiaFlashMissing('toast');

    expect($owner->fresh()->product_news_opt_in)->toBeTrue();
})->with([
    'missing' => [null],
    'unexpected array' => [['true']],
]);

it('requires authentication and verified email to manage preferences', function () {
    $this->get(route('email-updates.edit'))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)->get(route('email-updates.edit'))
        ->assertRedirect(route('verification.notice'));
});

it('keeps verification and recovery notifications available when news is opted out', function () {
    $user = User::factory()->create(['product_news_opt_in' => false]);
    Queue::fake([SendQueuedNotifications::class]);

    $user->sendEmailVerificationNotification();
    $user->sendPasswordResetNotification('synthetic-reset-token');

    Queue::assertPushed(SendQueuedNotifications::class, 2);
});
