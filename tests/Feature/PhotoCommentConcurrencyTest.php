<?php

use App\Actions\ManagePhotoComments;
use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoComment;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

function removePhotoCommentConcurrencyFixtures(int $actorId, int $ownerId, int $postId): void
{
    DB::table('photo_comments')->where('photo_post_id', $postId)->delete();
    DB::table('photo_posts')->where('id', $postId)->delete();
    DB::table('follows')->where('follower_id', $actorId)->where('followed_id', $ownerId)->delete();
    DB::table('users')->whereIn('id', [$actorId, $ownerId])->delete();
}

it('acknowledges one canonical record for simultaneous exact submissions', function () {
    if (getenv('MOMENTO_PHOTO_COMMENT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires MOMENTO_PHOTO_COMMENT_CONCURRENCY=1 and the isolated PostgreSQL fixture.');
    }

    $actor = User::factory()->create();
    $owner = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $actor->id, 'followed_id' => $owner->id]);
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $actorId = $actor->id;
    $ownerId = $owner->id;
    $postId = $post->id;
    $submissionKey = Str::uuid()->toString();
    $body = 'One canonical comment';
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-photo-comment-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);

    try {
        $tasks = [];
        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $actorId, $postId, $submissionKey, $body): array {
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent photo comment request timed out.');
                    }
                    usleep(10000);
                }
                $result = app(ManagePhotoComments::class)->add(User::findOrFail($actorId), $postId, $body, $submissionKey);

                return ['id' => $result['comment']->id, 'replayed' => $result['replayed']];
            }, null, null);
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 25);

        expect(array_unique(array_column($results, 'id')))->toHaveCount(1)
            ->and(count(array_filter($results, fn (array $result): bool => ! $result['replayed'])))->toBe(1)
            ->and(PhotoComment::query()->where('photo_post_id', $postId)->where('author_id', $actorId)->count())->toBe(1);
    } finally {
        removePhotoCommentConcurrencyFixtures($actorId, $ownerId, $postId);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});

it('does not resurrect text when an owner removal races an exact retry', function () {
    if (getenv('MOMENTO_PHOTO_COMMENT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires MOMENTO_PHOTO_COMMENT_CONCURRENCY=1 and the isolated PostgreSQL fixture.');
    }

    $actor = User::factory()->create();
    $owner = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $actor->id, 'followed_id' => $owner->id]);
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $submissionKey = Str::uuid()->toString();
    $body = 'Remove while retrying';
    $original = app(ManagePhotoComments::class)->add($actor, $post->id, $body, $submissionKey)['comment'];
    $actorId = $actor->id;
    $ownerId = $owner->id;
    $postId = $post->id;
    $commentId = $original->id;
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-photo-comment-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);

    try {
        $tasks = [];
        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $actorId, $ownerId, $postId, $commentId, $submissionKey, $body): string {
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent photo comment request timed out.');
                    }
                    usleep(10000);
                }
                if ($worker === 1) {
                    app(ManagePhotoComments::class)->remove(User::findOrFail($ownerId), $commentId);

                    return 'removed';
                }

                try {
                    $result = app(ManagePhotoComments::class)->add(User::findOrFail($actorId), $postId, $body, $submissionKey);

                    return $result['comment']->id === $commentId ? 'same-comment' : 'different-comment';
                } catch (HttpExceptionInterface $exception) {
                    if ($exception->getStatusCode() === 409) {
                        return 'removed-tombstone';
                    }

                    throw $exception;
                }
            }, null, null);
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 25);
        $comment = PhotoComment::query()->findOrFail($commentId);

        expect($results[0])->toBe('removed')
            ->and($results[1])->toBeIn(['same-comment', 'removed-tombstone'])
            ->and(PhotoComment::query()->where('photo_post_id', $postId)->count())->toBe(1)
            ->and($comment->body)->toBeNull()
            ->and($comment->removed_at)->not->toBeNull();
    } finally {
        removePhotoCommentConcurrencyFixtures($actorId, $ownerId, $postId);
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});
