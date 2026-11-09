<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoPost;
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
                    'createdAt' => $approvedPost->created_at->toIso8601String(),
                    'author' => ['id' => $approved->id, 'name' => 'Approved author'],
                    'mediaUrl' => route('photo-posts.media', $approvedPost),
                    'detailUrl' => route('photo-posts.show', $approvedPost),
                ],
                [
                    'id' => $ownPost->id,
                    'caption' => null,
                    'createdAt' => $ownPost->created_at->toIso8601String(),
                    'author' => ['id' => $viewer->id, 'name' => $viewer->name],
                    'mediaUrl' => route('photo-posts.media', $ownPost),
                    'detailUrl' => route('photo-posts.show', $ownPost),
                ],
            ])
            ->where('photoPosts.nextCursor', null)
            ->where('photoPosts.previousCursor', null)
            ->missing('photoPosts.data.0.user_id')
            ->missing('photoPosts.data.0.output_key')
            ->missing('photoPosts.data.0.raw_key')
            ->missing('photoPosts.data.0.email'));
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
