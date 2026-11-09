<?php

use App\Actions\ManagePhotoSaves;
use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

beforeEach(function () {
    $this->withoutVite();
});

function saveRequest(PhotoPost $post, bool $saved, int $revision, string $requestId): array
{
    return [
        'saved' => $saved,
        'revision' => $revision,
        'request_id' => $requestId,
    ];
}

it('persists owner-private saves and returns only a narrow newest-first projection', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $older = PhotoPost::factory()->for($author)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'Earlier photo',
    ]);
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $author->id]);
    $newer = PhotoPost::factory()->for($viewer)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'Latest photo',
    ]);

    $this->actingAs($viewer)->post(route('photo-saves.update', $older), saveRequest($older, true, 0, 'b078de57-0799-451c-8bd8-c89b3101bd54'))
        ->assertRedirectToRoute('saved.index');
    $this->post(route('photo-saves.update', $newer), saveRequest($newer, true, 0, '9b05782d-b6d5-4e6d-9df1-e9e1c8240ff5'))
        ->assertRedirectToRoute('saved.index');

    $this->get(route('saved.index'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('saved/index')
            ->where('savedPosts.data.0.id', $newer->id)
            ->where('savedPosts.data.0.caption', 'Latest photo')
            ->where('savedPosts.data.0.author', ['id' => $viewer->id, 'name' => $viewer->name])
            ->where('savedPosts.data.1.id', $older->id)
            ->where('savedPosts.total', 2)
            ->missing('savedPosts.data.0.raw_key')
            ->missing('savedPosts.data.0.output_key')
            ->missing('savedPosts.data.0.request_fingerprint')
            ->missing('savedPosts.data.0.author.email'));

    $this->actingAs(User::factory()->create())->get(route('saved.index'))
        ->assertInertia(fn (Assert $page) => $page->where('savedPosts.total', 0)->has('savedPosts.data', 0));
    expect(PhotoSave::query()->count())->toBe(2);
});

it('hides and later restores a saved post as directional approval changes', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = PhotoPost::factory()->for($author)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Private caption']);
    $follow = Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $author->id,
    ]);
    $this->actingAs($viewer)->post(route('photo-saves.update', $post), saveRequest($post, true, 0, '2643c6a7-f172-44e4-ac0b-c956d24077bd'))
        ->assertRedirectToRoute('saved.index');

    $this->delete(route('follows.destroy', $follow))->assertRedirect();
    $this->get(route('saved.index'))->assertInertia(fn (Assert $page) => $page
        ->where('savedPosts.total', 0)->has('savedPosts.data', 0));

    $follow = Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $author->id,
    ]);
    $this->get(route('saved.index'))->assertInertia(fn (Assert $page) => $page
        ->where('savedPosts.total', 1)
        ->where('savedPosts.data.0.id', $post->id)
        ->where('savedPosts.data.0.caption', 'Private caption'));
});

it('returns to the same feed cursor with acknowledged state after saving', function () {
    $this->freezeTime();
    $viewer = User::factory()->create();
    $posts = PhotoPost::factory()->count(13)->for($viewer)->create(['status' => PhotoPostStatus::Ready]);
    $firstPage = $this->actingAs($viewer)->get(route('dashboard'));
    $page = json_decode(json_encode($firstPage->viewData('page')), true);
    $cursor = $page['props']['photoPosts']['nextCursor'];
    expect($cursor)->toBeString()->not->toBeEmpty();
    $cursorUrl = route('dashboard', ['postsCursor' => $cursor]);
    $post = $posts->first();

    $this->from($cursorUrl)->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'ed4627a3-dfa3-47ef-bbe6-50c9727fcd14'))
        ->assertRedirect($cursorUrl)
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseHas('photo_saves', ['user_id' => $viewer->id, 'photo_post_id' => $post->id, 'is_saved' => true, 'revision' => 1]);
    $this->get($cursorUrl)->assertInertia(fn (Assert $page) => $page
        ->has('photoPosts.data', 1)
        ->where('photoPosts.data.0.id', $post->id)
        ->where('photoPosts.data.0.saveState', ['saved' => true, 'revision' => 1])
        ->where('photoPosts.nextCursor', null));
});

it('orders new saves and resaves by acknowledged intent even within one clock tick', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $first = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $second = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $this->actingAs($owner);
    $this->post(route('photo-saves.update', $second), saveRequest($second, true, 0, '1c7cf59f-b6d5-471a-bdf5-13559743ad42'));
    $this->post(route('photo-saves.update', $first), saveRequest($first, true, 0, '2c7cf59f-b6d5-471a-bdf5-13559743ad42'));
    $this->get(route('saved.index'))->assertInertia(fn (Assert $page) => $page->where('savedPosts.data.0.id', $first->id));

    $this->post(route('photo-saves.update', $second), saveRequest($second, false, 1, '3c7cf59f-b6d5-471a-bdf5-13559743ad42'));
    $this->post(route('photo-saves.update', $second), saveRequest($second, true, 2, '4c7cf59f-b6d5-471a-bdf5-13559743ad42'));
    $this->post(route('photo-saves.update', $first), saveRequest($first, true, 1, '5c7cf59f-b6d5-471a-bdf5-13559743ad42'));

    $this->get(route('saved.index'))->assertInertia(fn (Assert $page) => $page
        ->where('savedPosts.data.0.id', $second->id)->where('savedPosts.data.1.id', $first->id));
});

it('allows generic owner-only unsave after access is lost without returning post details', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = PhotoPost::factory()->for($author)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Private caption']);
    $follow = Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $author->id]);
    $this->actingAs($viewer)->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'ab9abf18-e696-4e1f-b9d0-9426b670bb64'));
    $this->delete(route('follows.destroy', $follow));

    $response = $this->post(route('photo-saves.update', $post), saveRequest($post, false, 1, '93f11e8b-03b5-475c-ae67-67104e97bd01'));
    $response->assertRedirectToRoute('saved.index');
    expect(PhotoSave::sole()->is_saved)->toBeFalse();
    expect($response->getContent())->not->toContain('Private caption')->not->toContain((string) $post->id);

    $unknown = PhotoPost::factory()->for($author)->create(['status' => PhotoPostStatus::Ready]);
    $this->post(route('photo-saves.update', $unknown), ['saved' => 'bad'])->assertNotFound();
    $this->post('/saved/999999', ['saved' => 'bad'])->assertNotFound();
});

it('uses expected revisions and request ids so retries are idempotent and stale writes cannot restore an old state', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $this->actingAs($owner);
    $saveRequestId = 'cf17725b-b278-4861-9629-600087ed3a9a';
    $payload = saveRequest($post, true, 0, $saveRequestId);

    $this->post(route('photo-saves.update', $post), $payload)->assertRedirectToRoute('saved.index');
    $this->post(route('photo-saves.update', $post), $payload)->assertRedirectToRoute('saved.index');
    expect(PhotoSave::sole()->revision)->toBe(1);

    $this->post(route('photo-saves.update', $post), saveRequest($post, false, 1, '8e802ea9-7c99-4bd6-b319-bf9b60cb8f5a'))
        ->assertRedirectToRoute('saved.index');
    $this->from(route('saved.index'))->post(route('photo-saves.update', $post), $payload)
        ->assertRedirect(route('saved.index'))->assertSessionHasErrors('save');
    expect(PhotoSave::sole()->is_saved)->toBeFalse();
    expect(PhotoSave::sole()->revision)->toBe(2);
});

it('does not follow a cross-origin referrer after a bookmark mutation', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);

    $this->actingAs($owner)->from('https://outside.invalid/photo')
        ->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'c4c724fb-fb33-425e-ae9e-80ae806a1590'))
        ->assertRedirectToRoute('saved.index');
});

it('bounds save requests per verified actor without changing the acknowledged state', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $payload = saveRequest($post, true, 0, '7c7cf59f-b6d5-471a-bdf5-13559743ad42');
    $this->actingAs($owner);

    foreach (range(1, 60) as $attempt) {
        $this->post(route('photo-saves.update', $post), $payload)->assertRedirectToRoute('saved.index');
    }
    $this->post(route('photo-saves.update', $post), $payload)
        ->assertTooManyRequests()->assertHeader('Retry-After');

    expect(PhotoSave::sole()->is_saved)->toBeTrue()->and(PhotoSave::sole()->revision)->toBe(1);
});

it('rejects reusing an acknowledged request id for the opposite save intent', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $key = 'fc7cf59f-b6d5-471a-bdf5-13559743ad42';
    $this->actingAs($owner)->post(route('photo-saves.update', $post), saveRequest($post, true, 0, $key))
        ->assertRedirectToRoute('saved.index');

    $this->from(route('saved.index'))->post(route('photo-saves.update', $post), saveRequest($post, false, 1, $key))
        ->assertRedirectToRoute('saved.index')->assertSessionHasErrors('save');

    expect(PhotoSave::sole()->is_saved)->toBeTrue()->and(PhotoSave::sole()->revision)->toBe(1);
});

it('records an initial unsave intent so an older revision cannot later save the photo', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $this->actingAs($owner)->post(route('photo-saves.update', $post), saveRequest($post, false, 0, 'ec7cf59f-b6d5-471a-bdf5-13559743ad42'))
        ->assertRedirectToRoute('saved.index');

    $this->from(route('saved.index'))->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'ac7cf59f-b6d5-471a-bdf5-13559743ad42'))
        ->assertRedirectToRoute('saved.index')->assertSessionHasErrors('save');

    expect(PhotoSave::sole()->is_saved)->toBeFalse()->and(PhotoSave::sole()->revision)->toBe(1);
});

it('rejects replaying the same save request with an altered expected revision', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $key = '6c7cf59f-b6d5-471a-bdf5-13559743ad42';
    $this->actingAs($owner)->post(route('photo-saves.update', $post), saveRequest($post, true, 0, $key))
        ->assertRedirectToRoute('saved.index');

    $this->from(route('saved.index'))->post(route('photo-saves.update', $post), saveRequest($post, true, 99, $key))
        ->assertRedirectToRoute('saved.index')->assertSessionHasErrors('save');

    expect(PhotoSave::sole()->is_saved)->toBeTrue()->and(PhotoSave::sole()->revision)->toBe(1);
});

it('denies a save when approval is removed between request admission and the transaction', function () {
    $follow = Follow::factory()->approved()->create();
    $viewer = $follow->follower;
    $post = PhotoPost::factory()->for($follow->followed)->create(['status' => PhotoPostStatus::Ready]);
    expect(app(PhotoAccess::class)->visiblePosts($viewer)->whereKey($post->id)->exists())->toBeTrue();
    $follow->delete();

    try {
        app(ManagePhotoSaves::class)->set($viewer, $post, true, 0, 'dc7cf59f-b6d5-471a-bdf5-13559743ad42');
        $this->fail('A revoked save must not return an acknowledgment.');
    } catch (HttpExceptionInterface $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }

    $this->assertDatabaseCount('photo_saves', 0);
});

it('serializes simultaneous first save and unsave intents without a false second acknowledgment', function () {
    if (getenv('MOMENTO_PHOTO_SAVE_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-save-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $ownerId = $owner->id;
    $postId = $post->id;

    try {
        $tasks = [];
        foreach ([true, false] as $worker => $desired) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $desired, $ownerId, $postId): bool {
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Save race synchronization timed out.');
                    }
                    usleep(10000);
                }

                try {
                    app(ManagePhotoSaves::class)->set(
                        User::findOrFail($ownerId), PhotoPost::findOrFail($postId), $desired, 0,
                        $worker === 0 ? 'bc7cf59f-b6d5-471a-bdf5-13559743ad42' : 'cc7cf59f-b6d5-471a-bdf5-13559743ad42',
                    );

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 25);

        expect(count(array_filter($results)))->toBe(1);
        expect(PhotoSave::sole()->revision)->toBe(1)
            ->and(PhotoSave::sole()->is_saved)->toBe($results[0]);
    } finally {
        PhotoSave::query()->where('user_id', $ownerId)->delete();
        PhotoPost::query()->whereKey($postId)->delete();
        User::query()->whereKey($ownerId)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});

it('keeps another owner from changing a bookmark and checks visibility before request validation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $this->actingAs($owner)->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'bcfae025-734e-4e0e-9ad8-464903e1bdad'));
    $hiddenPost = PhotoPost::factory()->create(['status' => PhotoPostStatus::Ready]);

    $this->actingAs($other)->post(route('photo-saves.update', $post), saveRequest($post, false, 0, '7f329e67-1ce4-4867-bdae-86c39544f54d'))
        ->assertNotFound();
    expect(PhotoSave::query()->where('user_id', $owner->id)->where('photo_post_id', $post->id)->sole()->is_saved)->toBeTrue();

    $this->post(route('photo-saves.update', $hiddenPost), ['saved' => 'invalid'])
        ->assertNotFound()->assertSessionDoesntHaveErrors();
});

it('requires verified authentication and CSRF for saved reads and mutations', function (bool $guest) {
    $post = PhotoPost::factory()->create(['status' => PhotoPostStatus::Ready]);
    if (! $guest) {
        $this->actingAs(User::factory()->unverified()->create());
    }

    $destination = $guest ? 'login' : 'verification.notice';
    $this->get(route('saved.index'))->assertRedirectToRoute($destination);
    $this->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'a94092ac-4230-4764-92c4-6a9722979a7e'))
        ->assertRedirectToRoute($destination);
    expect(PhotoSave::query()->count())->toBe(0);

    $this->actingAs(User::factory()->create());
    app()->instance('env', 'local');
    $this->post(route('photo-saves.update', $post), saveRequest($post, true, 0, 'a94092ac-4230-4764-92c4-6a9722979a7e'))
        ->assertStatus(419);
    expect(PhotoSave::query()->count())->toBe(0);
})->with([true, false]);

it('paginates current eligible bookmarks newest first without exposing hidden rows in counts', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $follow = Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $author->id]);
    $posts = PhotoPost::factory()->count(13)->for($author)->create(['status' => PhotoPostStatus::Ready]);

    foreach ($posts as $index => $post) {
        PhotoSave::query()->create([
            'user_id' => $viewer->id,
            'photo_post_id' => $post->id,
            'is_saved' => true,
            'revision' => 1,
            'last_request_id' => fake()->uuid(),
            'saved_order' => $index + 1,
        ]);
    }

    $this->actingAs($viewer)->get(route('saved.index'))->assertInertia(fn (Assert $page) => $page
        ->has('savedPosts.data', 12)
        ->where('savedPosts.total', 13)
        ->where('savedPosts.data.0.id', $posts->last()->id)
        ->has('savedPosts.next_page_url'));
    $this->get(route('saved.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('savedPosts.data', 1)
        ->where('savedPosts.total', 13)
        ->where('savedPosts.data.0.id', $posts->first()->id));

    $this->delete(route('follows.destroy', $follow));
    $this->get(route('saved.index'))->assertInertia(fn (Assert $page) => $page
        ->has('savedPosts.data', 0)->where('savedPosts.total', 0));
});
