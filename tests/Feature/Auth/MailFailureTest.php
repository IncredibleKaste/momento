<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

beforeEach(function () {
    config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Synthetic mail transport unavailable');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    Exceptions::fake([TransportException::class]);
});

test('registration mail failure preserves one unverified account and a neutral retry path', function () {
    $input = ['name' => 'Alex Example', 'email' => 'alex@example.test', 'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!'];

    $first = $this->post(route('register.store'), $input);
    $first->assertRedirect(route('login'))->assertSessionHas('status');
    $retry = $this->post(route('register.store'), $input);
    $retry->assertRedirect(route('login'))->assertSessionHas('status');

    expect(User::count())->toBe(1);
    expect(User::sole()->hasVerifiedEmail())->toBeFalse();
    $this->assertGuest();
    Exceptions::assertReported(TransportException::class);
});

test('verification mail failure reports retry without claiming a sent link', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('status');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Exceptions::assertReported(TransportException::class);
});

test('reset mail failure gives the same neutral retry response without authenticating', function () {
    $user = User::factory()->create();
    $original = $user->password;

    $this->postJson(route('password.email'), ['email' => $user->email])
        ->assertOk()->assertExactJson([
            'message' => 'If an eligible account exists, check its inbox for a reset link. If it does not arrive, wait a minute and try again.',
        ]);

    expect($user->fresh()->password)->toBe($original);
    $this->assertGuest();
    Exceptions::assertReported(TransportException::class);
});

test('a queued transport failure releases mail for a bounded retry without verifying the account', function () {
    $this->freezeTime();
    config(['queue.default' => 'database']);

    $this->postJson(route('register.store'), [
        'name' => 'Alex Example', 'email' => 'alex@example.test',
        'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertStatus(202);
    Exceptions::assertNothingReported();

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $job = DB::table('jobs')->sole();
    expect($job->attempts)->toBe(1)
        ->and($job->reserved_at)->toBeNull()
        ->and($job->available_at)->toBe(now()->addMinute()->timestamp);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(User::sole()->hasVerifiedEmail())->toBeFalse();
    $this->assertGuest();
    Exceptions::assertReported(TransportException::class);
});
