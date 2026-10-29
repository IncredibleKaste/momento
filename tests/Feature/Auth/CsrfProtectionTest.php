<?php

use App\Models\User;

test('authentication mutations reject requests without a CSRF token', function (string $route) {
    app()->instance('env', 'local');

    $this->post(route($route), [
        'name' => 'Alex Example', 'email' => 'alex@example.test',
        'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertStatus(419);

    expect(User::count())->toBe(0);
    $this->assertGuest();
})->with(['login.store', 'register.store', 'password.email', 'password.update', 'logout', 'verification.send']);
