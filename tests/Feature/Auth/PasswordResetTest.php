<?php

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset link responses do not reveal whether an email is registered', function () {
    Notification::fake([ResetPassword::class]);
    $user = User::factory()->create();

    $registered = $this->postJson(route('password.email'), ['email' => $user->email]);
    $unknown = $this->postJson(route('password.email'), ['email' => 'missing@example.test']);
    $throttled = $this->postJson(route('password.email'), ['email' => $user->email]);

    $registered->assertOk()->assertExactJson([
        'message' => 'If an eligible account exists, check its inbox for a reset link. If it does not arrive, wait a minute and try again.',
    ]);
    $unknown->assertOk()->assertExactJson($registered->json());
    $throttled->assertOk()->assertExactJson($registered->json());
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    $this->assertGuest();
});

test('reset link requests still reject an invalid email address', function () {
    Notification::fake([ResetPassword::class]);

    $this->postJson(route('password.email'), ['email' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Notification::assertNothingSent();
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('reset failure responses do not distinguish unknown addresses from invalid tokens', function () {
    $user = User::factory()->create();
    $input = ['token' => 'invalid', 'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!'];

    $known = $this->postJson(route('password.update'), [...$input, 'email' => $user->email]);
    $unknown = $this->postJson(route('password.update'), [...$input, 'email' => 'missing@example.test']);

    $known->assertUnprocessable()->assertJsonValidationErrors('email');
    $unknown->assertUnprocessable()->assertExactJson($known->json());
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

test('a used password reset token cannot change the password again', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $input = ['token' => $token, 'email' => $user->email, 'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!'];

    $this->post(route('password.update'), $input)->assertRedirect(route('login'));
    $this->postJson(route('password.update'), [...$input, 'password' => 'Another-passphrase-87!', 'password_confirmation' => 'Another-passphrase-87!'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(Hash::check('Sample-passphrase-48!', $user->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

test('an expired password reset token cannot change credentials', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

    $this->postJson(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('resetting an account without a local password cannot add local credentials', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => '']);
    $user->refresh();
    $token = Password::createToken($user);

    $this->postJson(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($user->fresh()->password)->toBe('');
    $this->assertGuest();
});
