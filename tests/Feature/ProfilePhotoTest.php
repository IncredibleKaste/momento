<?php

use App\Enums\PhotoPostStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

it('shows an owner only their ready photos through an allowlisted projection', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownPost = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'A shared caption',
    ]);
    PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Queued,
        'caption' => 'An unfinished caption',
    ]);
    PhotoPost::factory()->for($other)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'Another member caption',
    ]);

    $this->actingAs($owner)->get(route('members.show', $owner))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('members/show')
            ->where('member', ['id' => $owner->id, 'name' => $owner->name])
            ->where('photos.data', [[
                'id' => $ownPost->id,
                'caption' => 'A shared caption',
                'createdAt' => $ownPost->created_at->toIso8601String(),
                'mediaUrl' => route('photo-posts.media', $ownPost->id),
                'detailUrl' => route('photo-posts.show', $ownPost->id),
            ]])
            ->missing('photos.total')
            ->missing('photos.data.0.user_id')
            ->missing('photos.data.0.status')
            ->missing('photos.data.0.raw_key')
            ->missing('photos.data.0.output_key')
            ->missing('photos.data.0.request_fingerprint')
            ->missing('photos.data.0.attempt_token')
            ->missing('photos.data.0.idempotency_key')
            ->missing('photos.data.0.author'));
});

it('shows ready profile photos only to the currently approved directional follower', function (string $relationship) {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = PhotoPost::factory()->for($author)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'Approved photo caption',
    ]);
    match ($relationship) {
        'approved' => Follow::factory()->approved()->create([
            'follower_id' => $viewer->id,
            'followed_id' => $author->id,
        ]),
        'pending' => Follow::factory()->create([
            'follower_id' => $viewer->id,
            'followed_id' => $author->id,
        ]),
        'reverse approved' => Follow::factory()->approved()->create([
            'follower_id' => $author->id,
            'followed_id' => $viewer->id,
        ]),
    };

    $response = $this->actingAs($viewer)->get(route('members.show', $author));
    if ($relationship === 'approved') {
        $response->assertInertia(fn (Assert $page) => $page
            ->where('member', ['id' => $author->id, 'name' => $author->name])
            ->where('photos.data.0.id', $post->id)
            ->where('photos.data.0.caption', 'Approved photo caption')
            ->where('photos.data.0.mediaUrl', route('photo-posts.media', $post->id))
            ->where('photos.data.0.detailUrl', route('photo-posts.show', $post->id)));

        return;
    }

    $response->assertInertia(fn (Assert $page) => $page
        ->where('member', ['id' => $author->id, 'name' => $author->name])
        ->missing('photos')
        ->missing('caption')
        ->missing('posts')
        ->missing('photoCount'));
})->with(['approved', 'pending', 'reverse approved']);

it('removes photo props after approval is removed while retaining the minimal member identity', function () {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->followed)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'Revoked caption canary',
    ]);
    Storage::fake('photo_posts')->put($post->output_key, 'private profile photo bytes');
    $url = route('members.show', $follow->followed);
    $mediaUrl = route('photo-posts.media', $post);
    $this->actingAs($follow->follower)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('photos.data.0.id', $post->id));
    $this->get($mediaUrl)->assertOk();

    $follow->delete();

    $this->get($url)->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->where('member', ['id' => $follow->followed_id, 'name' => $follow->followed->name])
            ->where('follow', null)
            ->missing('photos')
            ->missing('Revoked caption canary'));
    $this->get($mediaUrl)->assertNotFound();
    $this->head($mediaUrl)->assertNotFound();
    $this->withHeader('Range', 'bytes=0-3')->get($mediaUrl)->assertNotFound();
    $this->getJson(route('photo-posts.show', $post))->assertNotFound()->assertDontSee('Revoked caption canary');
});

it('paginates newest first by timestamp and unique id without exposing totals', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $owner = User::factory()->create();
    $posts = PhotoPost::factory()->count(13)->for($owner)->create([
        'status' => PhotoPostStatus::Ready,
    ]);

    $profileUrl = route('members.show', $owner);
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));
    $firstPage = $this->actingAs($owner)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get($profileUrl);
    $firstPage->assertOk();
    $firstPage->assertJsonCount(12, 'props.photos.data')
        ->assertJsonPath('props.photos.data.0.id', $posts->last()->id)
        ->assertJsonPath('props.photos.data.11.id', $posts[1]->id)
        ->assertJsonMissingPath('props.photos.total');

    $nextPageUrl = $firstPage->json('props.photos.next_page_url');
    expect($nextPageUrl)->toBeString();
    $this->actingAs($owner)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->get($nextPageUrl)->assertOk()
        ->assertJsonCount(1, 'props.photos.data')
        ->assertJsonPath('props.photos.data.0.id', $posts->first()->id)
        ->assertJsonMissingPath('props.photos.total');

    $newest = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $this->withoutHeader('X-Inertia')->get(route('members.show', $owner))->assertInertia(fn (Assert $page) => $page
        ->where('photos.data.0.id', $newest->id));
    $this->travelBack();
});

it('resets malformed cursors to the member profile instead of returning a page error', function () {
    $owner = User::factory()->create();
    PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);

    $this->actingAs($owner)->get(route('members.show', $owner).'?cursor=not-a-cursor')
        ->assertRedirectToRoute('members.show', $owner)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('resets cursors with non-integer or out-of-range IDs', function (mixed $id) {
    $owner = User::factory()->create();
    $cursor = rtrim(strtr(base64_encode(json_encode([
        'created_at' => '2026-01-01T00:00:00.000000Z',
        'id' => $id,
        '_pointsToNextItems' => true,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    $this->actingAs($owner)->get(route('members.show', $owner).'?cursor='.urlencode($cursor))
        ->assertRedirectToRoute('members.show', $owner)
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'fractional ID' => 1.5,
    'scientific notation number' => 1e30,
    'scientific notation string' => '1e3',
    'integer overflow' => '9223372036854775808',
]);
