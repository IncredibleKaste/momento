<?php

use App\Actions\ManageConversation;
use App\Actions\ManageFollows;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('serializes competing normalized starts and a send racing with follow revocation', function (string $race) {
    if (getenv('MOMENTO_CONVERSATION_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $firstFollow = Follow::factory()->approved()->create(['follower_id' => $alice->id, 'followed_id' => $bob->id]);
    Follow::factory()->approved()->create(['follower_id' => $bob->id, 'followed_id' => $alice->id]);
    $conversation = $race === 'revoke'
        ? tap(Conversation::query()->create(['member_one_id' => min($alice->id, $bob->id), 'member_two_id' => max($alice->id, $bob->id)]), function (Conversation $conversation) use ($alice, $bob): void {
            $conversation->participants()->createMany([['user_id' => $alice->id], ['user_id' => $bob->id]]);
        })
        : null;
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-conversation-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $aliceId = $alice->id;
    $bobId = $bob->id;
    $followId = $firstFollow->id;
    $conversationId = $conversation?->id;

    try {
        $tasks = [];
        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $race, $aliceId, $bobId, $followId, $conversationId): bool {
                $alice = User::findOrFail($aliceId);
                $bob = User::findOrFail($bobId);
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent conversation request timed out.');
                    }
                    usleep(10000);
                }

                if ($race === 'start') {
                    app(ManageConversation::class)->start($worker === 1 ? $alice : $bob, $worker === 1 ? $bob : $alice);

                    return true;
                }

                if ($worker === 1) {
                    try {
                        app(ManageConversation::class)->send($alice, (int) $conversationId, 'Concurrent send', Str::uuid()->toString());
                    } catch (ValidationException) {
                        return false;
                    }

                    return true;
                }

                app(ManageFollows::class)->remove($alice, Follow::findOrFail($followId));

                return true;
            }, null, null);
        }

        Concurrency::driver('process')->run($tasks, timeout: 25);

        if ($race === 'start') {
            expect(Conversation::count())->toBe(1);
            expect(DB::table('conversation_participants')->count())->toBe(2);
        } else {
            expect(Follow::where('status', 'approved')->where(function ($query) use ($alice, $bob): void {
                $query->where(fn ($query) => $query->where('follower_id', $alice->id)->where('followed_id', $bob->id))
                    ->orWhere(fn ($query) => $query->where('follower_id', $bob->id)->where('followed_id', $alice->id));
            })->count())->toBe(1);
            expect(ConversationMessage::count())->toBeLessThanOrEqual(1);
        }
    } finally {
        User::whereKey([$alice->id, $bob->id])->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
})->with(['start', 'revoke']);
