<?php

use App\Jobs\SendProductNews as SendProductNewsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('queues product news only for opted-in accounts and reports queued count', function () {
    config(['queue.default' => 'database']);
    config([
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'url' => 'smtp://localhost'],
    ]);
    $eligible = User::factory()->create([
        'email' => 'eligible@example.test',
        'product_news_opt_in' => true,
    ]);
    User::factory()->create([
        'email' => 'not-eligible@example.test',
        'product_news_opt_in' => false,
    ]);
    User::factory()->unverified()->create([
        'email' => 'unverified@example.test',
        'product_news_opt_in' => true,
    ]);
    Queue::fake([SendProductNewsJob::class]);

    $this->artisan('momento:email-updates', [
        'subject' => 'Product update',
        'body' => 'A short product update.',
    ])->expectsOutputToContain('Queued 1 product news messages. Delivery is handled by the queue worker.')
        ->assertSuccessful();

    Queue::assertPushed(SendProductNewsJob::class, fn (SendProductNewsJob $job) => $job->userId === $eligible->id
            && $job->subject === 'Product update'
            && $job->body === 'A short product update.');
});

it('does not queue product news without an approved delivery mailer', function (array $configuration) {
    config($configuration);
    Queue::fake([SendProductNewsJob::class]);

    $this->artisan('momento:email-updates', [
        'subject' => 'Synthetic private subject',
        'body' => 'Synthetic private body with no real recipient.',
    ])->expectsOutputToContain('Product news delivery is not configured with an approved mailer.')
        ->assertFailed();

    Queue::assertNothingPushed();
})->with([
    'missing' => [['mail.product_news_mailer' => null]],
    'unknown' => [['mail.product_news_mailer' => 'missing-mailer']],
    'log' => [['mail.product_news_mailer' => 'log', 'mail.mailers.log.transport' => 'log']],
    'array' => [['mail.product_news_mailer' => 'array', 'mail.mailers.array.transport' => 'array']],
    'composite' => [['mail.product_news_mailer' => 'failover', 'mail.mailers.failover.transport' => 'failover']],
    'smtp URL resolving to log' => [[
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'url' => 'log://localhost'],
    ]],
    'smtp URL resolving to array' => [[
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'url' => 'array://localhost'],
    ]],
    'malformed mailer URL' => [[
        'mail.product_news_mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'url' => 'smtp://host:abc'],
    ]],
]);

it('rejects invalid subject headers without queueing a message', function (string $subject) {
    Queue::fake([SendProductNewsJob::class]);

    $this->artisan('momento:email-updates', [
        'subject' => $subject,
        'body' => 'A short product update.',
    ])->assertFailed();

    Queue::assertNothingPushed();
})->with([
    'multiline subject' => ["Product update\nInjected header"],
    'too long subject' => [str_repeat('x', 256)],
]);
