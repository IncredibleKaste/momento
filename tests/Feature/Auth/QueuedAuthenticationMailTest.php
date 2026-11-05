<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

test('authentication emails wait for a worker and use the isolated transport', function () {
    config(['queue.default' => 'database']);
    $input = ['name' => 'Alex Example', 'email' => 'alex@example.test', 'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!'];

    $this->post(route('register.store'), $input)->assertRedirect(route('login'));
    $user = User::sole();
    $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
    $this->actingAs($user)->post(route('verification.send'))->assertRedirect();

    expect(DB::table('jobs')->count())->toBe(3);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(3);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
