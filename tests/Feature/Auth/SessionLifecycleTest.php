<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

function resetSessionTestClient(): void
{
    Auth::forgetGuards();
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();
}

beforeEach(function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
});

test('login rotates a database session and logout prevents replay of the old authenticated cookie', function () {
    $user = User::factory()->create();
    $cookie = config('session.cookie');
    $guest = $this->get(route('login'))->getCookie($cookie)->getValue();
    resetSessionTestClient();

    $login = $this->withCookie($cookie, $guest)->post(route('login.store'), [
        'email' => $user->email, 'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
    $authenticated = $login->getCookie($cookie)->getValue();
    expect($authenticated)->not->toBe($guest);
    $this->assertDatabaseMissing('sessions', ['id' => $guest]);
    resetSessionTestClient();

    $this->withCookie($cookie, $authenticated)->get(route('dashboard'))->assertOk();
    resetSessionTestClient();
    $this->withCookie($cookie, $authenticated)->post(route('logout'))->assertRedirect(route('home'));
    $this->assertDatabaseMissing('sessions', ['id' => $authenticated]);
    resetSessionTestClient();

    $this->withCookie($cookie, $authenticated)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('password recovery revokes a previously authenticated session', function (bool $visitedMemberPage) {
    $user = User::factory()->create();
    $cookie = config('session.cookie');
    $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $captured = $login->getCookie($cookie)->getValue();
    resetSessionTestClient();
    if ($visitedMemberPage) {
        $this->withCookie($cookie, $captured)->get(route('dashboard'))->assertOk();
        resetSessionTestClient();
    }

    $token = Password::createToken($user);
    $this->withCookie($cookie, str_repeat('a', 40))->post(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertRedirect(route('login'));
    resetSessionTestClient();

    $this->withCookie($cookie, $captured)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with([true, false]);
