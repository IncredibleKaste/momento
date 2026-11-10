<?php

use App\Actions\ManageConversationRequests;
use App\Models\ConversationRequest;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('serializes reciprocal requests and sender caps on ordered user locks', function (string $race) {
    if (getenv('MOMENTO_CONVERSATION_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    $alice = User::factory()->create(['message_requests_enabled' => true]);
    $bob = User::factory()->create(['message_requests_enabled' => true]);
    $targets = [];
    $fixtureIds = [$alice->id, $bob->id];
    if (in_array($race, ['pending-cap', 'daily-cap'], true)) {
        foreach (range(1, 4) as $number) {
            $recipient = User::factory()->create(['message_requests_enabled' => true]);
            $fixtureIds[] = $recipient->id;
            ConversationRequest::factory()->create([
                'requester_id' => $alice->id,
                'recipient_id' => $recipient->id,
                'member_one_id' => min($alice->id, $recipient->id),
                'member_two_id' => max($alice->id, $recipient->id),
                'status' => $race === 'daily-cap' ? ConversationRequest::Declined : ConversationRequest::Pending,
                'created_at' => $race === 'pending-cap' ? now()->subDay() : now(),
                'updated_at' => $race === 'pending-cap' ? now()->subDay() : now(),
                'expires_at' => now()->addDays(29),
            ]);
        }
        $targets = [
            User::factory()->create(['message_requests_enabled' => true]),
            User::factory()->create(['message_requests_enabled' => true]),
        ];
        $fixtureIds = array_merge($fixtureIds, array_map(fn (User $target): int => $target->id, $targets));
    }

    DB::commit();
    $directory = sys_get_temp_dir().'/momento-message-request-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $aliceId = $alice->id;
    $bobId = $bob->id;
    $targetIds = array_map(fn (User $target): int => $target->id, $targets);

    try {
        $tasks = [];
        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $race, $aliceId, $bobId, $targetIds): bool {
                $alice = User::findOrFail($aliceId);
                $bob = User::findOrFail($bobId);
                $requester = in_array($race, ['pending-cap', 'daily-cap'], true) ? $alice : ($worker === 1 ? $alice : $bob);
                $recipient = in_array($race, ['pending-cap', 'daily-cap'], true)
                    ? User::findOrFail($targetIds[$worker - 1])
                    : ($worker === 1 ? $bob : $alice);
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent request start timed out.');
                    }
                    usleep(10000);
                }

                try {
                    app(ManageConversationRequests::class)->initiate(
                        $requester,
                        $recipient,
                        "Request from worker {$worker}",
                        Str::uuid()->toString(),
                    );
                } catch (ValidationException) {
                    return false;
                }

                return true;
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 25);

        if ($race === 'reciprocal') {
            expect(ConversationRequest::count())->toBe(1);
            expect(ConversationRequest::query()->where('member_one_id', min($aliceId, $bobId))
                ->where('member_two_id', max($aliceId, $bobId))->count())->toBe(1);
        } elseif ($race === 'pending-cap') {
            expect(ConversationRequest::query()->where('requester_id', $aliceId)->count())->toBe(5);
            expect(collect($results)->filter()->count())->toBe(1);
        } else {
            expect(ConversationRequest::query()->where('requester_id', $aliceId)->whereDate('created_at', now()->toDateString())->count())->toBe(5);
            expect(collect($results)->filter()->count())->toBe(1);
            expect(ConversationRequest::query()->where('requester_id', $aliceId)->where('status', ConversationRequest::Pending)->count())->toBe(1);
        }
    } finally {
        User::whereKey($fixtureIds)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
})->with(['reciprocal', 'pending-cap', 'daily-cap']);
