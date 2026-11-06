<?php

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

test('factor management refuses an account without local credentials', function (string $route) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => '']);

    $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson(route($route), ['code' => '123456'])->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBeNull();
})->with(['two-factor.enable', 'two-factor.confirm', 'two-factor.regenerate-recovery-codes']);

test('factor management requires a fresh password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
        ->postJson(route('two-factor.enable'))->assertStatus(423);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('the owner confirms enrollment before the factor becomes active', function () {
    $this->freezeTime();
    $engine = twoFactorTestClock();
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson(route('two-factor.enable'), ['user_id' => $other->id])->assertOk();

    $owner->refresh();
    expect($owner->hasEnabledTwoFactorAuthentication())->toBeFalse();
    expect($owner->recoveryCodes())->toHaveCount(8);
    expect($other->fresh()->two_factor_secret)->toBeNull();

    $key = $this->getJson(route('two-factor.secret-key'))->assertOk()->json('secretKey');
    expect($owner->two_factor_secret)->not->toBe($key);
    expect(decrypt($owner->two_factor_secret))->toBe($key);

    $this->postJson(route('two-factor.confirm'), ['code' => 'invalid'])->assertUnprocessable();
    expect($owner->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
    $this->postJson(route('two-factor.confirm'), ['code' => $engine->getCurrentOtp($key)])->assertOk();
    expect($owner->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('a local password alone cannot skip an enrolled authenticator challenge', function () {
    $this->freezeTime();
    $engine = twoFactorTestClock();
    $secret = 'JBSWY3DPEHPK3PXP';
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->postJson(route('two-factor.login.store'), ['code' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['code' => $engine->getCurrentOtp($secret)])
        ->assertRedirect(route('dashboard', absolute: false))->assertSessionMissing('login.id');
    $this->assertAuthenticatedAs($user);
});

test('a recovery code is consumed once and its replay cannot authenticate', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->withSession(['login.id' => $user->id])->postJson(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertNoContent();
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');

    $this->post(route('logout'));
    $this->withSession(['login.id' => $user->id])->postJson(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
    $this->assertGuest();
});

test('regeneration invalidates old recovery codes and removal clears the factor only for its owner', function () {
    $user = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();
    $otherSecret = $other->two_factor_secret;

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson(route('two-factor.regenerate-recovery-codes'), ['user_id' => $other->id])->assertOk();
    expect($user->fresh()->recoveryCodes())->toHaveCount(8)->not->toContain('recovery-code-1');
    expect($other->fresh()->recoveryCodes())->toBe(['recovery-code-1']);

    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
        ->deleteJson(route('two-factor.disable'))->assertStatus(423);
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->deleteJson(route('two-factor.disable'), ['user_id' => $other->id])->assertOk();
    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($other->fresh()->two_factor_secret)->toBe($otherSecret);
});

test('unconfirmed enrollment cannot be used to finish a pending login', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]);

    $this->withSession(['login.id' => $user->id])->postJson(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertUnprocessable();
    $this->assertGuest();
    expect($user->fresh()->recoveryCodes())->toBe(['recovery-code-1']);
});

test('confirmation rejects malformed codes without changing enrollment', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson(route('two-factor.confirm'), ['code' => ['123456']])->assertUnprocessable();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('security settings preserve pending enrollment across reloads', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('twoFactorEnabled', false)->where('twoFactorPending', true));
});

test('factor management is rate limited without modifying enrollment', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('two-factor.confirm'), ['code' => 'invalid'])->assertUnprocessable();
    }

    $this->postJson(route('two-factor.confirm'), ['code' => '123456'])->assertTooManyRequests();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('mutating factor management requires CSRF protection', function (string $method, string $route) {
    $user = User::factory()->withTwoFactor()->create();
    $this->app->instance('env', 'local');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->json($method, route($route), ['code' => '123456'])->assertStatus(419);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
})->with([
    ['POST', 'two-factor.enable'], ['POST', 'two-factor.confirm'],
    ['POST', 'two-factor.regenerate-recovery-codes'], ['DELETE', 'two-factor.disable'],
]);

test('credentialless identities cannot manage or confirm passkeys', function (string $method, string $route) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => '']);

    $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->json($method, route($route))->assertForbidden();
})->with([
    ['GET', 'passkey.registration-options'], ['POST', 'passkey.store'],
    ['GET', 'passkey.confirm-options'], ['POST', 'passkey.confirm'],
]);

test('passkey sign-in and registration require user verification and local credentials', function () {
    $this->getJson(route('passkey.login-options'))->assertOk()->assertJsonPath('options.userVerification', 'required');
    $user = User::factory()->create();
    expect(app(GenerateRegistrationOptions::class)($user)->authenticatorSelection->userVerification)->toBe('required');
    $passkey = new Passkey;
    $passkey->setRelation('user', $user);
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeTrue();
    $user->setRawAttributes(['password' => '']);
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeFalse();
});

test('an unavailable replay cache cannot authenticate or remove the factor', function () {
    $engine = twoFactorTestClock();
    $secret = 'JBSWY3DPEHPK3PXP';
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('add')->once()->andThrow(new RuntimeException('Replay cache unavailable.'));
    app()->instance(TwoFactorAuthenticationProvider::class, new App\Actions\Fortify\TwoFactorAuthenticationProvider($engine, $cache));

    $this->withSession(['login.id' => $user->id])->postJson(route('two-factor.login.store'), ['code' => $engine->getCurrentOtp($secret)])
        ->assertStatus(500);

    $this->assertGuest();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('a failed factor removal preserves the enrolled factor', function () {
    $user = User::factory()->withTwoFactor()->create();
    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'update "users"') && str_contains($query, 'two_factor_secret')) {
            throw new RuntimeException('Factor update unavailable.');
        }
    });

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->deleteJson(route('two-factor.disable'))->assertStatus(500);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
    expect($user->fresh()->recoveryCodes())->toBe(['recovery-code-1']);
});
