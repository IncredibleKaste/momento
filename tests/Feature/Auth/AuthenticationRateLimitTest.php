<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('registration and recovery limit requests across different email addresses', function (string $route) {
    Notification::fake();
    $this->withServerVariables(['REMOTE_ADDR' => match ($route) {
        'register.store' => '192.0.2.10',
        'password.email' => '192.0.2.11',
        default => '192.0.2.12',
    }]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route($route), ['email' => "person{$attempt}@example.test"])
            ->assertStatus($route === 'password.email' ? 200 : 422);
    }

    $this->postJson(route($route), ['email' => 'another@example.test'])
        ->assertStatus(429)->assertHeader('Retry-After');

    expect(User::count())->toBe(0);
    $this->assertGuest();
})->with(['register.store', 'password.email', 'password.update']);
