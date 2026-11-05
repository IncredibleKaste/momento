<?php

use App\Actions\ManageFollows;
use App\Models\Follow;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

it('serializes competing requests and revocation against the current request episode', function (string $race) {
    if (getenv('MOMENTO_FOLLOW_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $followId = $race === 'revoke' ? Follow::factory()->create(['follower_id' => $alice->id, 'followed_id' => $bob->id])->id : null;
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-follow-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    try {
        $tasks = [];
        $aliceId = $alice->id;
        $bobId = $bob->id;
        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $race, $aliceId, $bobId, $followId): bool {
                $alice = User::findOrFail($aliceId);
                $bob = User::findOrFail($bobId);
                $follow = $followId ? Follow::findOrFail($followId) : null;
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent follow request timed out.');
                    }
                    usleep(10000);
                }
                $actions = app(ManageFollows::class);
                try {
                    if ($race === 'revoke') {
                        if ($worker === 1) {
                            $actions->approve($bob, $follow);
                        } else {
                            $actions->remove($alice, $follow);
                        }
                    } else {
                        $actions->request($race === 'reciprocal' && $worker === 2 ? $bob : $alice,
                            $race === 'reciprocal' && $worker === 2 ? $alice : $bob);
                    }
                } catch (ModelNotFoundException) {
                    return false;
                }

                return true;
            }, null, null);
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 20);
        expect($results)->toContain(true);
        $expected = match ($race) {
            'duplicate' => 1, 'reciprocal' => 2, default => 0
        };
        expect(Follow::count())->toBe($expected);
        expect(Follow::where('status', 'approved')->count())->toBe(0);
        expect(app(PhotoAccess::class)->allows($alice, $bob))->toBeFalse();
    } finally {
        User::whereKey([$alice->id, $bob->id])->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
})->with(['duplicate', 'reciprocal', 'revoke']);
