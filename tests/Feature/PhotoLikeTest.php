<?php

use App\Actions\PhotoLikeState;
use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoLike;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function photoLikePayload(bool $liked, int $revision, ?string $requestId = null): array
{
    return [
        'liked' => $liked,
        'revision' => $revision,
        'request_id' => $requestId ?? (string) Str::uuid(),
    ];
}

function readyPhotoPost(?User $author = null): PhotoPost
{
    return PhotoPost::factory()->for($author ?? User::factory())->create(['status' => PhotoPostStatus::Ready]);
}

it('stores the actor selected state and treats exact retries as the same write', function () {
    $actor = User::factory()->create();
    $post = readyPhotoPost($actor);
    $requestId = (string) Str::uuid();
    $payload = photoLikePayload(true, 0, $requestId);

    $this->actingAs($actor)->postJson(route('photo-likes.store', $post), $payload)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['liked' => true, 'revision' => 1]);
    $this->postJson(route('photo-likes.store', $post), $payload)
        ->assertOk()->assertExactJson(['liked' => true, 'revision' => 1]);

    expect(PhotoLike::sole()->user_id)->toBe($actor->id)
        ->and(PhotoLike::sole()->photo_post_id)->toBe($post->id)
        ->and(PhotoLike::sole()->liked)->toBeTrue()
        ->and(PhotoLike::sole()->revision)->toBe(1);
});

it('rejects stale replays and re-used request IDs without undoing the latest state', function () {
    $actor = User::factory()->create();
    $post = readyPhotoPost($actor);
    $firstId = (string) Str::uuid();
    $secondId = (string) Str::uuid();

    $this->actingAs($actor)->postJson(route('photo-likes.store', $post), photoLikePayload(true, 0, $firstId))->assertOk();
    $this->postJson(route('photo-likes.store', $post), photoLikePayload(false, 1, $secondId))
        ->assertOk()->assertExactJson(['liked' => false, 'revision' => 2]);
    $this->postJson(route('photo-likes.store', $post), photoLikePayload(true, 0, $firstId))
        ->assertStatus(409)->assertExactJson(['liked' => false, 'revision' => 2]);
    $this->postJson(route('photo-likes.store', $post), photoLikePayload(true, 1, $secondId))
        ->assertStatus(409)->assertExactJson(['liked' => false, 'revision' => 2]);

    expect(PhotoLike::sole()->liked)->toBeFalse()->and(PhotoLike::sole()->revision)->toBe(2);
});

it('allows self-likes and ignores forged actor fields', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $post = readyPhotoPost($actor);

    $this->actingAs($actor)->postJson(route('photo-likes.store', $post), [
        ...photoLikePayload(true, 0),
        'user_id' => $other->id,
        'photo_post_id' => 999999,
    ])->assertOk()->assertExactJson(['liked' => true, 'revision' => 1]);

    expect(PhotoLike::sole()->user_id)->toBe($actor->id)->and(PhotoLike::sole()->photo_post_id)->toBe($post->id);
});

it('denies inaccessible and non-ready posts before disclosing validation errors', function () {
    $actor = User::factory()->create();
    $author = User::factory()->create();
    $post = readyPhotoPost($author);
    $staging = PhotoPost::factory()->for($actor)->create();

    $this->actingAs($actor)->postJson(route('photo-likes.store', $post), [])->assertNotFound();
    $this->postJson(route('photo-likes.store', $staging), [])->assertNotFound();
    $this->postJson(route('photo-likes.store', 999999), [])->assertNotFound();
    $this->assertDatabaseCount('photo_likes', 0);
});

it('rechecks approved-follow access and stops allowing the next mutation after revocation', function () {
    $viewer = User::factory()->create();
    $follow = Follow::factory()->approved()->create(['follower_id' => $viewer->id]);
    $post = readyPhotoPost($follow->followed);

    $this->actingAs($viewer)->postJson(route('photo-likes.store', $post), photoLikePayload(true, 0))->assertOk();
    $follow->delete();
    $this->postJson(route('photo-likes.store', $post), photoLikePayload(false, 1))->assertNotFound();

    expect(PhotoLike::sole()->liked)->toBeTrue()->and(PhotoLike::sole()->revision)->toBe(1);
});

it('projects only the current viewer state for currently visible posts', function () {
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    $ownPost = readyPhotoPost($viewer);
    $hiddenPost = readyPhotoPost($other);
    PhotoLike::query()->create([
        'user_id' => $other->id,
        'photo_post_id' => $ownPost->id,
        'liked' => true,
        'revision' => 1,
    ]);
    PhotoLike::query()->create([
        'user_id' => $viewer->id,
        'photo_post_id' => $hiddenPost->id,
        'liked' => true,
        'revision' => 1,
    ]);

    expect(app(PhotoLikeState::class)->forViewer($viewer, [$ownPost->id, $hiddenPost->id], app(PhotoAccess::class)))
        ->toBe([$ownPost->id => ['liked' => false, 'revision' => 0]]);
});

it('requires CSRF protection for the state change', function () {
    $actor = User::factory()->create();
    $post = readyPhotoPost($actor);
    $this->app->instance('env', 'local');

    $this->actingAs($actor)->postJson(route('photo-likes.store', $post), photoLikePayload(true, 0))->assertStatus(419);
    $this->assertDatabaseCount('photo_likes', 0);

    $token = str_repeat('a', 40);
    $page = $this->withSession(['_token' => $token])->get(route('home'));
    $xsrfHeader = $page->getCookie('XSRF-TOKEN', false)?->getValue();
    expect($xsrfHeader)->toBeString();
    $this->withHeader('X-XSRF-TOKEN', $xsrfHeader)
        ->postJson(route('photo-likes.store', $post), photoLikePayload(true, 0))->assertOk();
    $this->assertDatabaseCount('photo_likes', 1);
});

it('rate limits like changes per authenticated actor', function () {
    $actor = User::factory()->create();
    $post = readyPhotoPost($actor);
    $this->actingAs($actor);
    $payload = photoLikePayload(true, 0);

    foreach (range(1, 60) as $attempt) {
        $this->postJson(route('photo-likes.store', $post), $payload)->assertOk();
    }

    $this->postJson(route('photo-likes.store', $post), $payload)->assertTooManyRequests()->assertHeader('Retry-After');
    expect(PhotoLike::sole()->revision)->toBe(1);
});

it('serializes simultaneous first writes for one actor and post', function () {
    if (getenv('MOMENTO_PHOTO_LIKE_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    DB::commit();
    $actor = User::factory()->create();
    $post = readyPhotoPost($actor);
    $directory = sys_get_temp_dir().'/momento-photo-like-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);

    try {
        $tasks = [];
        foreach ([true, false] as $worker => $liked) {
            $requestId = (string) Str::uuid();
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $liked, $requestId, $actor, $post): array {
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent like start timed out.');
                    }
                    usleep(10000);
                }

                $state = app(PhotoLikeState::class)->handle($actor, $post, $liked, 0, $requestId, app(PhotoAccess::class));

                return [$state['conflict'], $state['liked'], $state['revision']];
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 15);
        expect(count(array_filter($results, fn (array $result): bool => ! $result[0])))->toBe(1)
            ->and(PhotoLike::sole()->revision)->toBe(1)
            ->and(PhotoLike::sole()->liked)->toBe($results[0][0] ? $results[1][1] : $results[0][1]);
    } finally {
        PhotoLike::query()->where('user_id', $actor->id)->delete();
        $post->delete();
        $actor->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});
