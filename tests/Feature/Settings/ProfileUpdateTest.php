<?php

use App\Models\MicrosoftIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const PROFILE_TEST_TENANT = '11111111-1111-4111-8111-111111111111';

beforeEach(function () {
    config(['services.microsoft' => [
        'client_id' => '22222222-2222-4222-8222-222222222222',
        'client_secret' => 'fictional-test-secret',
        'redirect_uri' => 'http://localhost/auth/microsoft/callback',
        'tenants' => ['example' => ['id' => PROFILE_TEST_TENANT, 'name' => 'Example organization']],
    ]]);
});

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('provider-only profile clearly marks its contact email as read-only', function () {
    $user = User::factory()->create(['password' => null]);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => PROFILE_TEST_TENANT]);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/profile')->where('canChangeEmail', false));
});

test('provider-only users can update their name but cannot change their contact email', function () {
    $user = User::factory()->create(['password' => null, 'email' => 'provider@example.test']);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => PROFILE_TEST_TENANT]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Updated name',
        'email' => strtoupper($user->email),
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Updated name')
        ->and($user->fresh()->email)->toBe('provider@example.test');

    $this->patch(route('profile.update'), [
        'name' => 'Should not save',
        'email' => 'victim@example.test',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->name)->toBe('Updated name')
        ->and($user->fresh()->email)->toBe('provider@example.test');
});

test('provider-only name updates preserve legacy email casing and verification', function () {
    $user = User::factory()->create(['password' => null]);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => PROFILE_TEST_TENANT]);
    $verifiedAt = now()->subDay()->startOfSecond();
    DB::table('users')->where('id', $user->id)->update([
        'email' => 'Legacy.Case@example.test',
        'email_verified_at' => $verifiedAt,
    ]);
    $user->refresh();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Updated legacy name',
        'email' => 'legacy.case@example.test',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Updated legacy name')
        ->and($user->fresh()->email)->toBe('Legacy.Case@example.test')
        ->and($user->fresh()->email_verified_at->toDateTimeString())->toBe($verifiedAt->toDateTimeString());
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('account deletion is forbidden even with the correct password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response->assertForbidden();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();
});

test('account deletion stays forbidden with an incorrect password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response->assertForbidden();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();
});
