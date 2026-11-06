<?php

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    Notification::fake([VerifyEmail::class]);
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $user = User::sole();
    expect($user->email)->toBe('test@example.com');
    expect($user->hasVerifiedEmail())->toBeFalse();
    expect(Hash::check('password', $user->password))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('registration responses do not reveal an existing account or change its credentials', function () {
    Notification::fake([VerifyEmail::class]);
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    $originalPassword = $owner->password;
    $input = ['name' => 'Alex Example', 'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!'];

    $new = $this->postJson(route('register.store'), [...$input, 'email' => 'new@example.test']);
    $existing = $this->postJson(route('register.store'), [...$input, 'email' => $owner->email]);

    $new->assertAccepted();
    $existing->assertAccepted()->assertExactJson($new->json());
    $this->assertGuest();
    expect(User::count())->toBe(2);
    expect($owner->fresh()->password)->toBe($originalPassword);
    Notification::assertNotSentTo($owner, VerifyEmail::class);
    Notification::assertSentTo(User::where('email', 'new@example.test')->sole(), VerifyEmail::class);
});

test('registration validation does not include a duplicate email error alongside other errors', function () {
    $owner = User::factory()->create();

    $response = $this->postJson(route('register.store'), ['email' => $owner->email]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'password'])
        ->assertJsonMissingValidationErrors('email');
    expect(User::count())->toBe(1);
});
