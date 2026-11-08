<?php

use App\Jobs\SendProductNews;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

it('skips a queued product news message after the recipient opts out', function () {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    $user = User::factory()->create([
        'email' => 'synthetic-recipient@example.test',
        'product_news_opt_in' => true,
    ]);
    SendProductNews::dispatch($user->id, 'Product update', 'A short product update.');
    $user->forceFill(['product_news_opt_in' => false])->save();

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('sends plain text with an unsubscribe link only for a currently opted-in account', function () {
    config([
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp'],
    ]);
    $user = User::factory()->create([
        'email' => 'synthetic-recipient@example.test',
        'product_news_opt_in' => true,
    ]);
    $mailer = Mockery::mock(Mailer::class);
    $messageBody = null;

    Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
    $mailer->shouldReceive('raw')
        ->once()
        ->with(Mockery::on(function (string $body) use (&$messageBody, $user): bool {
            $messageBody = $body;

            return str_contains($body, 'A short product update.')
                && str_contains($body, 'email-updates/unsubscribe/'.$user->id);
        }), Mockery::on(function (Closure $callback) use ($user): bool {
            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->once()->with($user->email)->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('Product update')->andReturnSelf();
            $callback($message);

            return true;
        }))
        ->andReturn(Mockery::mock(LaravelSentMessage::class));

    (new SendProductNews($user->id, 'Product update', 'A short product update.'))->handle();

    expect($messageBody)->toContain('A short product update.')
        ->and($messageBody)->toContain('email-updates/unsubscribe/'.$user->id)
        ->and($messageBody)->not->toContain('<html');
});

it('propagates a synthetic mail transport failure for the worker to retry without logging message content', function () {
    $this->freezeTime();
    config(['queue.default' => 'database', 'mail.product_news_mailer' => 'smtp']);
    config([
        'mail.mailers.smtp' => ['transport' => 'smtp'],
    ]);
    Mail::extend('smtp', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('Synthetic transport error with private message body.');
        }

        public function __toString(): string
        {
            return 'synthetic-smtp';
        }
    });
    Exceptions::fake([RuntimeException::class]);
    $user = User::factory()->create([
        'email' => 'synthetic-recipient@example.test',
        'product_news_opt_in' => true,
    ]);
    SendProductNews::dispatch($user->id, 'Product update', 'A short update.');

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $job = DB::table('jobs')->sole();
    expect($job->attempts)->toBe(1)
        ->and($job->reserved_at)->toBeNull()
        ->and($job->available_at)->toBe(now()->addMinute()->timestamp)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not send product news to an address that is no longer verified', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'synthetic-recipient@example.test',
        'product_news_opt_in' => true,
    ]);
    Mail::shouldReceive('mailer')->never();

    (new SendProductNews($user->id, 'Product update', 'A short update.'))->handle();
});

it('retries a queued message if the configured delivery mailer becomes unsafe', function () {
    $this->freezeTime();
    config([
        'queue.default' => 'database',
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp'],
    ]);
    $user = User::factory()->create([
        'email' => 'synthetic-recipient@example.test',
        'product_news_opt_in' => true,
    ]);
    SendProductNews::dispatch($user->id, 'Product update', 'Private synthetic body.');
    config([
        'mail.product_news_mailer' => 'log',
        'mail.mailers.log' => ['transport' => 'log'],
    ]);
    Mail::shouldReceive('mailer')->never();
    Exceptions::fake([RuntimeException::class]);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $job = DB::table('jobs')->sole();
    expect($job->attempts)->toBe(1)
        ->and($job->reserved_at)->toBeNull()
        ->and($job->available_at)->toBe(now()->addMinute()->timestamp)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('retries a message canceled by a MessageSending listener instead of consuming it as sent', function () {
    $this->freezeTime();
    config([
        'queue.default' => 'database',
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 1,
        ],
    ]);
    Event::listen(MessageSending::class, fn (MessageSending $event): bool => false);
    Exceptions::fake([RuntimeException::class]);
    $user = User::factory()->create([
        'email' => 'synthetic-recipient@example.test',
        'product_news_opt_in' => true,
    ]);
    SendProductNews::dispatch($user->id, 'Product update', 'Private synthetic body.');

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $job = DB::table('jobs')->sole();
    expect($job->attempts)->toBe(1)
        ->and($job->reserved_at)->toBeNull()
        ->and($job->available_at)->toBe(now()->addMinute()->timestamp)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});
