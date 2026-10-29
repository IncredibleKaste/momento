<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    Exceptions::fake();
});

test('session storage failure returns retry without publishing a login session', function (string $operation) {
    $user = User::factory()->create();
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    DB::connection()->beforeExecuting(function (string $query) use ($operation) {
        if (str_contains($query, '"sessions"') && str_starts_with($query, $operation)) {
            throw new PDOException('Synthetic session storage failure');
        }
    });

    $response = $this->postJson(route('login.store'), [
        'email' => $user->email, 'password' => 'password',
    ])->assertStatus(503)->assertHeader('Retry-After', '60')
        ->assertExactJson(['message' => 'The service is temporarily unavailable. Please retry in a minute.']);

    expect($response->getCookie(config('session.cookie')))->toBeNull();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
})->with(['select', 'insert']);

test('an unavailable Redis limiter denies registration with a retry response', function () {
    if (config('cache.default') !== 'redis' || (int) config('database.redis.cache.port') !== 1) {
        $this->markTestSkipped('Requires the isolated Redis outage process.');
    }

    $response = $this->postJson(route('register.store'), [
        'name' => 'Alex Example', 'email' => 'alex@example.test',
        'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertStatus(503)->assertHeader('Retry-After', '60')
        ->assertExactJson(['message' => 'The service is temporarily unavailable. Please retry in a minute.']);

    expect($response->getCookie(config('session.cookie')))->toBeNull();
    expect(User::count())->toBe(0);
    $this->assertGuest();
});
