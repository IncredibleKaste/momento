<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('does not reveal whether an unsigned unsubscribe link names an account', function () {
    $owner = User::factory()->create();

    $existing = $this->get("/email-updates/unsubscribe/{$owner->id}")->getStatusCode();
    $missing = $this->get('/email-updates/unsubscribe/999999999')->getStatusCode();

    expect($existing)->toBe($missing)
        ->and($existing)->toBe(403);
});

it('shows a confirmation page without changing consent on get', function () {
    $owner = User::factory()->create(['product_news_opt_in' => true]);
    $signedInUser = User::factory()->create(['email' => 'unrelated@example.test']);
    $url = URL::temporarySignedRoute(
        'email-updates.unsubscribe.confirm',
        now()->addDay(),
        ['user' => $owner->id],
    );

    $this->actingAs($signedInUser)->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('email-updates/unsubscribe')
        ->missing('auth')
        ->where('action', $url));

    expect($owner->fresh()->product_news_opt_in)->toBeTrue();
});

it('unsubscribes only the account named by a valid signed post', function () {
    $owner = User::factory()->create(['product_news_opt_in' => true]);
    $other = User::factory()->create(['product_news_opt_in' => true]);
    $url = URL::temporarySignedRoute(
        'email-updates.unsubscribe.confirm',
        now()->addDay(),
        ['user' => $owner->id],
    );

    $this->post($url)->assertInertia(fn (Assert $page) => $page->component('email-updates/unsubscribed'));

    expect($owner->fresh()->product_news_opt_in)->toBeFalse()
        ->and($other->fresh()->product_news_opt_in)->toBeTrue();
});

it('rejects expired or tampered unsubscribe links without changing consent', function (string $failure) {
    $owner = User::factory()->create(['product_news_opt_in' => true]);
    $url = URL::temporarySignedRoute(
        'email-updates.unsubscribe.confirm',
        $failure === 'expired' ? now()->subMinute() : now()->addDay(),
        ['user' => $owner->id],
    );

    if ($failure === 'tampered') {
        $url = str_replace('signature=', 'signature=x', $url);
    }

    $this->post($url)->assertForbidden();

    expect($owner->fresh()->product_news_opt_in)->toBeTrue();
})->with(['expired', 'tampered']);

it('requires a session csrf token before the signed post can change consent', function () {
    $owner = User::factory()->create(['product_news_opt_in' => true]);
    $url = URL::temporarySignedRoute(
        'email-updates.unsubscribe.confirm',
        now()->addDay(),
        ['user' => $owner->id],
    );
    app()->detectEnvironment(fn (): string => 'production');

    $this->post($url)->assertStatus(419);

    expect($owner->fresh()->product_news_opt_in)->toBeTrue();
});
