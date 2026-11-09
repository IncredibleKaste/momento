<?php

use App\Actions\ManagePhotoPosts;
use App\Enums\PhotoPostStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function photoPostDeletionUpload(string $caption): UploadedFile
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//2Q==');

    return UploadedFile::fake()->createWithContent('camera.jpg', $jpeg);
}

function readyPhotoPost(User $owner, array $attributes = []): PhotoPost
{
    return PhotoPost::factory()->for($owner)->create(array_merge([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'A private caption',
        'staging_bytes_charged' => 0,
        'processed_bytes_charged' => 30,
        'cleanup_completed_at' => now(),
    ], $attributes));
}

it('deletes an owned ready post, immediately hides it, and retains a non-content tombstone', function () {
    $owner = User::factory()->create();
    $post = readyPhotoPost($owner, [
        'caption' => 'A private caption canary',
        'processed_bytes_charged' => 73,
    ]);
    $key = $post->idempotency_key;
    $disk = Storage::fake('photo_posts');
    $disk->put($post->raw_key, 'synthetic private original');
    $disk->put($post->output_key, 'synthetic private WebP');

    $this->actingAs($owner)->delete(route('photo-posts.destroy', $post))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Your photo post was deleted.');

    $post->refresh();
    expect($post->status)->toBe(PhotoPostStatus::Deleted)
        ->and($post->caption)->toBeNull()
        ->and($post->request_fingerprint)->toBe(str_repeat('0', 64))
        ->and($post->idempotency_key)->toBe($key)
        ->and($post->deleted_at)->not->toBeNull()
        ->and($post->cleanup_completed_at)->not->toBeNull()
        ->and($post->cleanup_due_at)->toBeNull()
        ->and($post->processed_bytes_charged)->toBe(0)
        ->and(app(PhotoAccess::class)->visiblePosts($owner)->whereKey($post->id)->exists())->toBeFalse();
    $disk->assertMissing($post->raw_key);
    $disk->assertMissing($post->output_key);

    $this->get(route('photo-posts.show', $post))->assertNotFound()->assertDontSee('A private caption canary');
    $this->head(route('photo-posts.media', $post))->assertNotFound();
    $this->post(route('photo-posts.cancel', $post))->assertNotFound();
});

it('denies another account and keeps the ready post untouched', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $post = readyPhotoPost($owner);
    $disk = Storage::fake('photo_posts');
    $disk->put($post->output_key, 'synthetic private WebP');

    $this->actingAs($other)->delete(route('photo-posts.destroy', $post))->assertNotFound();

    expect($post->fresh()->status)->toBe(PhotoPostStatus::Ready)
        ->and($post->fresh()->caption)->toBe('A private caption')
        ->and($post->fresh()->processed_bytes_charged)->toBe(30);
    $disk->assertExists($post->output_key);
});

it('projects delete permission only to the owner of a ready post', function () {
    $owner = User::factory()->create();
    $follower = User::factory()->create();
    Follow::factory()->approved()->create([
        'follower_id' => $follower->id,
        'followed_id' => $owner->id,
    ]);
    $post = readyPhotoPost($owner);
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version ?? ''];

    $this->actingAs($owner)->withHeaders($headers)->get(route('photo-posts.show', $post))
        ->assertOk()
        ->assertJsonPath('props.photoPost.canDelete', true);
    $this->actingAs($follower)->withHeaders($headers)->get(route('photo-posts.show', $post))
        ->assertOk()
        ->assertJsonPath('props.photoPost.canDelete', false);
    $this->delete(route('photo-posts.destroy', $post))->assertNotFound();

    expect($post->fresh()->status)->toBe(PhotoPostStatus::Ready);
});

it('does not resurrect a deleted operation on replay and gives repeat deletion a truthful result', function () {
    $owner = User::factory()->create();
    $caption = 'The same input';
    $upload = photoPostDeletionUpload($caption);
    $inputHash = hash_file('sha256', $upload->getRealPath());
    $post = readyPhotoPost($owner, [
        'caption' => $caption,
        'idempotency_key' => (string) Str::uuid(),
        'request_fingerprint' => hash('sha256', $inputHash."\0".$caption),
    ]);
    Storage::fake('photo_posts');
    $this->actingAs($owner)->delete(route('photo-posts.destroy', $post))->assertRedirect(route('dashboard'));
    $deletedToken = $post->fresh()->attempt_token;
    $replay = ['photo' => $upload, 'caption' => $caption, 'idempotency_key' => $post->idempotency_key];

    $this->post(route('photo-posts.store'), $replay)->assertStatus(409);
    expect(PhotoPost::count())->toBe(1)
        ->and($post->fresh()->status)->toBe(PhotoPostStatus::Deleted)
        ->and($post->fresh()->attempt_token)->toBe($deletedToken)
        ->and($post->fresh()->request_fingerprint)->toBe(str_repeat('0', 64));

    $this->delete(route('photo-posts.destroy', $post))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Your photo post was deleted.');
    expect(PhotoPost::count())->toBe(1);
});

it('keeps deleted media charged after storage failure and releases it only after verified reconciliation', function () {
    $owner = User::factory()->create();
    $post = readyPhotoPost($owner, ['processed_bytes_charged' => 91]);
    $root = Storage::fake('photo_posts')->path('');
    $objects = [$post->raw_key => true, $post->output_key => true];
    $allowDelete = false;
    $disk = Mockery::mock();
    $disk->shouldReceive('path')->andReturnUsing(fn (string $key): string => $root.'/'.$key);
    $disk->shouldReceive('exists')->andReturnUsing(function (string $key) use (&$objects): bool {
        return $objects[$key] ?? false;
    });
    $disk->shouldReceive('delete')->andReturnUsing(function (string $key) use (&$objects, &$allowDelete): bool {
        if (! $allowDelete) {
            return false;
        }
        unset($objects[$key]);

        return true;
    });
    Storage::shouldReceive('disk')->with('photo_posts')->andReturn($disk);

    $this->actingAs($owner)->delete(route('photo-posts.destroy', $post))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Your photo post was removed. Photo cleanup will retry.');
    expect($post->fresh()->status)->toBe(PhotoPostStatus::Deleted)
        ->and($post->fresh()->caption)->toBeNull()
        ->and($post->fresh()->processed_bytes_charged)->toBe(91)
        ->and($post->fresh()->cleanup_completed_at)->toBeNull();
    $this->get(route('photo-posts.show', $post))->assertNotFound();

    $allowDelete = true;
    $post->update(['cleanup_due_at' => now()->subSecond()]);
    expect(app(ManagePhotoPosts::class)->reconcile())->toBe(1)
        ->and($post->fresh()->status)->toBe(PhotoPostStatus::Deleted)
        ->and($post->fresh()->processed_bytes_charged)->toBe(0)
        ->and($post->fresh()->cleanup_completed_at)->not->toBeNull()
        ->and($objects)->toBe([]);
});

it('keeps a deletion tombstone and charge when storage is removed but the accounting transaction fails', function () {
    $owner = User::factory()->create();
    $post = readyPhotoPost($owner, ['processed_bytes_charged' => 44]);
    $disk = Storage::fake('photo_posts');
    $disk->put($post->output_key, 'synthetic private WebP');
    $shouldFail = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$shouldFail): void {
        if ($shouldFail && str_contains($query, 'processed_bytes_charged')) {
            $shouldFail = false;
            throw new RuntimeException('synthetic accounting transaction failure');
        }
    });

    $this->actingAs($owner)->delete(route('photo-posts.destroy', $post))->assertServerError();

    expect($post->fresh()->status)->toBe(PhotoPostStatus::Deleted)
        ->and($post->fresh()->processed_bytes_charged)->toBe(44)
        ->and($post->fresh()->cleanup_completed_at)->toBeNull();
    $disk->assertMissing($post->output_key);
    $this->get(route('photo-posts.show', $post))->assertNotFound();

    expect(app(ManagePhotoPosts::class)->reconcile())->toBe(1)
        ->and($post->fresh()->processed_bytes_charged)->toBe(0)
        ->and($post->fresh()->cleanup_completed_at)->not->toBeNull();
});

it('clamps the pre-deadline retry, then reports an overdue cleanup once', function () {
    $owner = User::factory()->create();
    $deletedAt = now()->subHours(23)->subMinutes(59)->toImmutable();
    $post = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Deleted,
        'caption' => null,
        'request_fingerprint' => str_repeat('0', 64),
        'deleted_at' => $deletedAt,
        'cleanup_due_at' => now()->subSecond(),
        'cleanup_completed_at' => null,
        'staging_bytes_charged' => 0,
        'processed_bytes_charged' => 91,
    ]);
    $root = Storage::fake('photo_posts')->path('');
    $disk = Mockery::mock();
    $disk->shouldReceive('path')->andReturnUsing(fn (string $key): string => $root.'/'.$key);
    $disk->shouldReceive('exists')->andReturnTrue();
    $disk->shouldReceive('delete')->andReturnFalse();
    Storage::shouldReceive('disk')->with('photo_posts')->andReturn($disk);
    Log::spy();

    expect(app(ManagePhotoPosts::class)->delete($owner, $post))->toBeFalse()
        ->and($post->fresh()->cleanup_due_at->timestamp)->toBe($deletedAt->addDay()->timestamp);

    $this->travelTo($deletedAt->addDay()->addSecond());
    expect(app(ManagePhotoPosts::class)->delete($owner, $post))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->with(
        'Photo post media cleanup is past the 24-hour deletion deadline.',
        ['photo_post_id' => $post->id],
    );

    $this->travel(2)->minutes();
    expect(app(ManagePhotoPosts::class)->reconcile())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with(
        'Photo post media cleanup is past the 24-hour deletion deadline.',
        ['photo_post_id' => $post->id],
    );
    expect($post->fresh()->processed_bytes_charged)->toBe(91)
        ->and($post->fresh()->cleanup_completed_at)->toBeNull();
});

it('serializes concurrent deletion retries under the same PostgreSQL operation guard', function () {
    if (getenv('MOMENTO_F25_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly enabled isolated PostgreSQL concurrency fixture.');
    }

    $owner = User::factory()->create();
    $post = readyPhotoPost($owner, ['processed_bytes_charged' => 65]);
    $ownerId = $owner->id;
    $postId = $post->id;
    DB::commit();
    $directory = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).'/momento-photo-delete-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $photoRoot = $directory.'/media';
    mkdir($photoRoot, 0700);
    mkdir(dirname($photoRoot.'/'.$post->output_key), 0700, true);
    file_put_contents($photoRoot.'/'.$post->output_key, 'synthetic private WebP');
    $barrier = $directory.'/ready';

    try {
        $tasks = [];
        for ($i = 0; $i < 2; $i++) {
            $tasks[] = Closure::bind(static function () use ($ownerId, $postId, $photoRoot, $barrier): string {
                config(['filesystems.disks.photo_posts.root' => $photoRoot]);
                touch($barrier.'/'.getmypid());
                $deadline = microtime(true) + 15;
                while (count(glob($barrier.'/*') ?: []) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Photo deletion race barrier timed out.');
                    }
                    usleep(10000);
                }
                $result = app(ManagePhotoPosts::class)->delete(
                    User::query()->findOrFail($ownerId),
                    PhotoPost::query()->findOrFail($postId),
                );

                return $result ? 'clean' : 'pending';
            }, null, null);
        }

        mkdir($barrier, 0700);
        $results = Concurrency::driver('process')->run($tasks, timeout: 45);
        expect($results)->toContain('clean')
            ->and($results)->each(fn ($result) => $result->toBeIn(['clean', 'pending']))
            ->and(PhotoPost::query()->findOrFail($postId)->status)->toBe(PhotoPostStatus::Deleted)
            ->and(PhotoPost::query()->findOrFail($postId)->processed_bytes_charged)->toBe(0)
            ->and(file_exists($photoRoot.'/'.$post->output_key))->toBeFalse();
    } finally {
        PhotoPost::query()->whereKey($postId)->delete();
        User::query()->whereKey($ownerId)->delete();
        $remove = function (string $path) use (&$remove): void {
            if (is_dir($path) && ! is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path.'/'.$entry);
                    }
                }
                rmdir($path);

                return;
            }
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        };
        $remove($directory);
        DB::beginTransaction();
    }
});
