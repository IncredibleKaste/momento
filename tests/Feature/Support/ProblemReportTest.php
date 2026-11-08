<?php

use App\Models\User;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    config([
        'services.problem_reports.recipient' => 'support@example.test',
        'services.problem_reports.mailer' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp'],
    ]);
});

it('shows the form only to verified members', function () {
    $user = User::factory()->create();

    $this->get(route('problem-reports.create'))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('problem-reports.create'))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($user)
        ->get(route('problem-reports.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('support/report-a-problem')
            ->where('status', null),
        );
});

it('sends only the member description to the configured operator and confirms acceptance', function () {
    $description = 'The timeline did not load after I followed a member.';
    $user = User::factory()->create(['name' => 'Private Reporter', 'email' => 'member@example.test']);

    if ($port = env('F20_SMTP_SINK_PORT')) {
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'username' => null,
            'password' => null,
            'timeout' => 2,
            'local_domain' => 'localhost',
        ]]);
    } else {
        $mailer = Mockery::mock(Mailer::class);

        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')
            ->once()
            ->with($description, Mockery::type(Closure::class))
            ->andReturn(Mockery::mock(SentMessage::class));
    }

    $this->actingAs($user)
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), [
            'description' => $description,
            'user_id' => 999,
            'email' => 'forged@example.test',
        ])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHas('status', 'Your report was sent to the support team.')
        ->assertSessionHasNoErrors();

});

it('preserves a retryable validation error without claiming delivery', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => 'Too short'])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHasErrors([
            'description' => 'The description field must be at least 10 characters.',
        ])
        ->assertSessionMissing('status');
});

it('rejects a description that exceeds the text limit', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => str_repeat('a', 5001)])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHasErrors('description')
        ->assertSessionMissing('status');
});

it('preserves the draft when the mail transport rejects the report', function () {
    $mailer = Mockery::mock(Mailer::class);
    Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
    $mailer->shouldReceive('raw')->once()->andThrow(new TransportException('Synthetic failure'));

    $this->actingAs(User::factory()->create())
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => 'The page froze while saving.'])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHasErrors('description')
        ->assertSessionMissing('status');
});

it('does not confirm delivery when a MessageSending listener cancels the email', function () {
    Event::listen(MessageSending::class, fn (MessageSending $event): bool => false);

    $this->actingAs(User::factory()->create())
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => 'The page froze while saving.'])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHasErrors('description')
        ->assertSessionMissing('status');
});

it('fails closed when the operator or a real mail transport is not configured', function (array $configuration) {
    config($configuration);

    Mail::shouldReceive('mailer')->never();

    $this->actingAs(User::factory()->create())
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => 'The page froze while saving.'])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHasErrors('description')
        ->assertSessionMissing('status');
})->with([
    'missing operator' => [['services.problem_reports.recipient' => null]],
    'invalid operator' => [['services.problem_reports.recipient' => 'not-an-address']],
    'array transport' => [['services.problem_reports.mailer' => 'array', 'mail.mailers.array.transport' => 'array']],
    'log transport' => [['services.problem_reports.mailer' => 'log', 'mail.mailers.log.transport' => 'log']],
    'failover transport' => [['services.problem_reports.mailer' => 'failover', 'mail.mailers.failover.transport' => 'failover']],
]);

it('limits reports per member while allowing another member to report', function () {
    $mailer = Mockery::mock(Mailer::class);
    Mail::shouldReceive('mailer')->times(4)->with('smtp')->andReturn($mailer);
    $mailer->shouldReceive('raw')->times(4)->andReturn(Mockery::mock(SentMessage::class));
    $firstMember = User::factory()->create();
    $secondMember = User::factory()->create();

    foreach (range(1, 3) as $number) {
        $this->actingAs($firstMember)
            ->from(route('problem-reports.create'))
            ->post(route('problem-reports.store'), ['description' => "Report number {$number} is long enough."])
            ->assertRedirect(route('problem-reports.create'))
            ->assertSessionHas('status');
    }

    $this->actingAs($firstMember)
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => 'A fourth report should be limited.'])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHasErrors('description')
        ->assertSessionMissing('status');

    $this->actingAs($secondMember)
        ->from(route('problem-reports.create'))
        ->post(route('problem-reports.store'), ['description' => 'Another member can still submit.'])
        ->assertRedirect(route('problem-reports.create'))
        ->assertSessionHas('status');
});

it('rejects report posts without a CSRF token', function () {
    app()->instance('env', 'local');

    $this->actingAs(User::factory()->create())
        ->post(route('problem-reports.store'), ['description' => 'The page froze while saving.'])
        ->assertStatus(419);
});
