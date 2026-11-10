<?php

use App\Models\Follow;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile name can be updated without changing the account email', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('message request preference can be updated without submitting the profile name', function () {
    $user = User::factory()->create(['name' => 'Saved name']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'message_requests_enabled' => true,
    ])->assertRedirectToRoute('profile.edit')
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Saved name');
    expect($user->fresh()->message_requests_enabled)->toBeTrue();
});

test('profile updates ignore supplied account and security attributes', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerPassword = $owner->getAuthPassword();
    $ownerVerifiedAt = $owner->email_verified_at;
    $ownerEmail = $owner->email;
    $otherName = $other->name;
    $otherEmail = $other->email;

    $response = $this->actingAs($owner)->patch(route('profile.update'), [
        'name' => 'Updated public name',
        'user_id' => $other->id,
        'email_verified_at' => now()->subDay()->toDateTimeString(),
        'password' => 'attacker-supplied-password',
        'two_factor_secret' => 'attacker-supplied-secret',
        'two_factor_recovery_codes' => 'attacker-supplied-codes',
    ]);

    $response->assertRedirectToRoute('profile.edit')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Profile updated.');

    $owner->refresh();
    $other->refresh();

    expect($owner->name)->toBe('Updated public name');
    expect($owner->email)->toBe($ownerEmail);
    expect($owner->email_verified_at)->toEqual($ownerVerifiedAt);
    expect($owner->getAuthPassword())->toBe($ownerPassword);
    expect($owner->two_factor_secret)->toBeNull();
    expect($owner->two_factor_recovery_codes)->toBeNull();
    expect($other->name)->toBe($otherName);
    expect($other->email)->toBe($otherEmail);
});

test('updated public name reaches the member and approved-follower projections', function () {
    $owner = User::factory()->create(['name' => 'Original name']);
    $follower = User::factory()->create();
    $follow = Follow::factory()->approved()->create([
        'follower_id' => $follower->id,
        'followed_id' => $owner->id,
    ]);

    $this->actingAs($owner)->patch(route('profile.update'), [
        'name' => 'Updated public name',
    ])->assertRedirectToRoute('profile.edit')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Profile updated.');

    $owner->refresh();

    $this->actingAs($follower)->get(route('members.show', $owner))
        ->assertInertia(fn (Assert $page) => $page->component('members/show')
            ->where('member', ['id' => $owner->id, 'name' => 'Updated public name'])
            ->where('follow', ['id' => $follow->id, 'status' => 'approved']));

    $this->get(route('following.index'))
        ->assertInertia(fn (Assert $page) => $page->component('following/index')
            ->where('following.data.0.member', ['id' => $owner->id, 'name' => 'Updated public name']));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')
            ->where('followedPeople', [['id' => $owner->id, 'name' => 'Updated public name']]));
});

test('invalid profile names preserve the saved name and do not flash success', function (string $name, string $message) {
    $owner = User::factory()->create(['name' => 'Original name']);
    $other = User::factory()->create(['name' => 'Other member']);

    $response = $this->actingAs($owner)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $name,
            'user_id' => $other->id,
        ]);

    $response->assertRedirectToRoute('profile.edit')
        ->assertSessionHasErrors(['name' => $message])
        ->assertInertiaFlashMissing('toast');

    expect($owner->fresh()->name)->toBe('Original name');
    expect($other->fresh()->name)->toBe('Other member');
})->with([
    'empty name' => ['', 'The name field is required.'],
    'name exceeds the accepted limit' => [str_repeat('x', 256), 'The name field must not be greater than 255 characters.'],
]);

test('profile name updates preserve email verification status', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('profile update rejects any supplied email and preserves the saved account details', function (?string $email) {
    $user = User::factory()->create(['name' => 'Original name']);
    $originalEmail = $user->email;
    $verifiedAt = $user->email_verified_at;

    $response = $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Attempted name change',
            'email' => $email,
        ]);

    $response->assertRedirectToRoute('profile.edit')
        ->assertSessionHasErrors('email')
        ->assertInertiaFlashMissing('toast');

    expect($user->fresh()->name)->toBe('Original name');
    expect($user->fresh()->email)->toBe($originalEmail);
    expect($user->fresh()->email_verified_at)->toEqual($verifiedAt);
})->with([
    'null email' => [null],
    'empty email' => [''],
    'changed email' => ['changed@example.com'],
]);

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
