<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoLike;
use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('returns only the current member’s ready own and approved-follow photos', function () {
    $viewer = User::factory()->create();
    $approved = User::factory()->create(['name' => 'Approved author']);
    $pending = User::factory()->create();
    $reverse = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $approved->id,
    ]);
    Follow::factory()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $pending->id,
    ]);
    Follow::factory()->approved()->create([
        'follower_id' => $reverse->id,
        'followed_id' => $viewer->id,
    ]);
    Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $unverified->id,
    ]);

    $ownPost = PhotoPost::factory()->for($viewer)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => null,
        'created_at' => now()->subMinute(),
    ]);
    $approvedPost = PhotoPost::factory()->for($approved)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => '<script>plain text only</script>',
        'location' => '<script>location canary</script>',
        'created_at' => now(),
    ]);
    PhotoPost::factory()->for($pending)->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->for($reverse)->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->for($unverified)->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->for($approved)->create(['status' => PhotoPostStatus::Queued]);

    $this->actingAs($viewer)->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('photoPosts.data', [
                [
                    'id' => $approvedPost->id,
                    'caption' => '<script>plain text only</script>',
                    'location' => '<script>location canary</script>',
                    'createdAt' => $approvedPost->created_at->toIso8601String(),
                    'author' => ['id' => $approved->id, 'name' => 'Approved author'],
                    'mediaUrl' => route('photo-posts.media', $approvedPost),
                    'detailUrl' => route('photo-posts.show', $approvedPost),
                    'likeState' => ['liked' => false, 'revision' => 0],
                    'saveState' => ['saved' => false, 'revision' => 0],
                ],
                [
                    'id' => $ownPost->id,
                    'caption' => null,
                    'location' => null,
                    'createdAt' => $ownPost->created_at->toIso8601String(),
                    'author' => ['id' => $viewer->id, 'name' => $viewer->name],
                    'mediaUrl' => route('photo-posts.media', $ownPost),
                    'detailUrl' => route('photo-posts.show', $ownPost),
                    'likeState' => ['liked' => false, 'revision' => 0],
                    'saveState' => ['saved' => false, 'revision' => 0],
                ],
            ])
            ->where('photoPosts.nextCursor', null)
            ->where('photoPosts.previousCursor', null)
            ->missing('photoPosts.data.0.user_id')
            ->missing('photoPosts.data.0.output_key')
            ->missing('photoPosts.data.0.raw_key')
            ->missing('photoPosts.data.0.email'))
        ->assertDontSee('<script>location canary</script>', false);
});

it('returns only viewer action states including toggled-off revisions and absent defaults', function () {
    $this->freezeTime();
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $other->id]);
    $active = PhotoPost::factory()->for($other)->create(['status' => PhotoPostStatus::Ready]);
    $off = PhotoPost::factory()->for($other)->create(['status' => PhotoPostStatus::Ready, 'created_at' => now()->subMinute()]);
    $absent = PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready, 'created_at' => now()->subMinutes(2)]);

    foreach ([[$viewer, $active, true, 3], [$viewer, $off, false, 8], [$other, $absent, true, 9]] as [$member, $post, $selected, $revision]) {
        PhotoLike::query()->create([
            'user_id' => $member->id,
            'photo_post_id' => $post->id,
            'liked' => $selected,
            'revision' => $revision,
        ]);
        PhotoSave::query()->create([
            'user_id' => $member->id,
            'photo_post_id' => $post->id,
            'is_saved' => $selected,
            'revision' => $revision,
            'last_request_id' => fake()->uuid(),
        ]);
    }

    $this->actingAs($viewer)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('photoPosts.data', 3)
            ->where('photoPosts.data.0.id', $active->id)
            ->where('photoPosts.data.0.likeState', ['liked' => true, 'revision' => 3])
            ->where('photoPosts.data.0.saveState', ['saved' => true, 'revision' => 3])
            ->where('photoPosts.data.1.id', $off->id)
            ->where('photoPosts.data.1.likeState', ['liked' => false, 'revision' => 8])
            ->where('photoPosts.data.1.saveState', ['saved' => false, 'revision' => 8])
            ->where('photoPosts.data.2.id', $absent->id)
            ->where('photoPosts.data.2.likeState', ['liked' => false, 'revision' => 0])
            ->where('photoPosts.data.2.saveState', ['saved' => false, 'revision' => 0])
            ->missing('photoPosts.data.0.likeState.user_id')
            ->missing('photoPosts.data.0.saveState.last_request_id'));
});

it('omits posts and their action state after deletion or follow revocation', function () {
    $viewer = User::factory()->create();
    $follow = Follow::factory()->approved()->create(['follower_id' => $viewer->id]);
    $deleted = PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready]);
    $revoked = PhotoPost::factory()->for($follow->followed)->create(['status' => PhotoPostStatus::Ready]);

    foreach ([$deleted, $revoked] as $post) {
        PhotoLike::query()->create(['user_id' => $viewer->id, 'photo_post_id' => $post->id, 'liked' => true, 'revision' => 1]);
        PhotoSave::query()->create([
            'user_id' => $viewer->id,
            'photo_post_id' => $post->id,
            'is_saved' => true,
            'revision' => 1,
            'last_request_id' => fake()->uuid(),
        ]);
    }
    $deleted->delete();
    $follow->delete();

    $this->actingAs($viewer)->get(route('dashboard'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->has('photoPosts.data', 0));
});

it('uses a stable newest-first cursor and refresh starts from the latest post', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $author->id,
    ]);

    $posts = collect(range(0, 12))->map(fn (int $offset): PhotoPost => PhotoPost::factory()
        ->for($author)
        ->create([
            'status' => PhotoPostStatus::Ready,
            'created_at' => Carbon::now()->subMinutes($offset),
        ]));

    $firstPage = $this->actingAs($viewer)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('photoPosts.data', 12)
            ->where('photoPosts.data.0.id', $posts[0]->id)
            ->where('photoPosts.data.11.id', $posts[11]->id)
            ->where('photoPosts.previousCursor', null));

    $page = json_decode(json_encode($firstPage->viewData('page')), true);
    $cursor = $page['props']['photoPosts']['nextCursor'];
    expect($cursor)->toBeString()->not->toBeEmpty();
    $insertedAfterPageOne = PhotoPost::factory()->for($author)->create([
        'status' => PhotoPostStatus::Ready,
        'created_at' => Carbon::now()->addSecond(),
    ]);

    $this->get(route('dashboard', ['postsCursor' => $cursor]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('photoPosts.data', 1)
            ->where('photoPosts.data.0.id', $posts[12]->id)
            ->where('photoPosts.nextCursor', null));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('photoPosts.data.0.id', $insertedAfterPageOne->id)
            ->has('photoPosts.data', 12));
});

it('rechecks relationship and media access on each fresh page and media request', function () {
    $follow = Follow::factory()->approved()->create();
    $posts = collect(range(0, 12))->map(fn (int $offset): PhotoPost => PhotoPost::factory()
        ->for($follow->followed)
        ->create([
            'status' => PhotoPostStatus::Ready,
            'created_at' => Carbon::now()->subMinutes($offset),
        ]));

    Storage::fake('photo_posts');
    Storage::disk('photo_posts')->put($posts[12]->output_key, 'synthetic image bytes');

    $this->actingAs($follow->follower);
    $firstPage = $this->get(route('dashboard'))->assertOk();
    $page = json_decode(json_encode($firstPage->viewData('page')), true);
    $cursor = $page['props']['photoPosts']['nextCursor'];
    expect($cursor)->toBeString()->not->toBeEmpty();

    $follow->delete();

    $this->get(route('dashboard', ['postsCursor' => $cursor]))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->has('photoPosts.data', 0));
    $this->get(route('photo-posts.media', $posts[12]))->assertNotFound();
});

it('shows a usable empty state and protects feed pages behind verified sessions', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('photoPosts.data', 0)
            ->where('photoPosts.nextCursor', null)
            ->where('photoPosts.previousCursor', null));

    auth()->logout();
    $this->get(route('dashboard'))->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))->assertRedirectToRoute('verification.notice');
});
