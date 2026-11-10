<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoLike;
use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

it('does not list photos without a search query', function (array $parameters) {
    $owner = User::factory()->create();
    PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Mountain morning']);

    $this->actingAs($owner)->get(route('people.index', $parameters))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->where('q', null)->where('photos', null)->where('people', null));
})->with([[[]], [['q' => '']], [['q' => '   ']]]);

it('matches literal captions only within currently visible ready photos', function () {
    $this->freezeTime();
    $viewer = User::factory()->create();
    $approved = User::factory()->create(['name' => 'Mountain author']);
    $pending = User::factory()->create();
    $reverse = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $approved->id]);
    Follow::factory()->create(['follower_id' => $viewer->id, 'followed_id' => $pending->id]);
    Follow::factory()->approved()->create(['follower_id' => $reverse->id, 'followed_id' => $viewer->id]);
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $unverified->id]);
    $own = PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Mountain morning', 'created_at' => now()->subMinute()]);
    $visible = PhotoPost::factory()->for($approved)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => '<b>Mountain afternoon</b>',
        'location' => 'Mountain',
    ]);
    foreach ([$pending, $reverse, $unverified, User::factory()->create()] as $hiddenAuthor) {
        PhotoPost::factory()->for($hiddenAuthor)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Mountain hidden']);
    }
    foreach ([PhotoPostStatus::Queued, PhotoPostStatus::Failed, PhotoPostStatus::Deleted] as $status) {
        PhotoPost::factory()->for($approved)->create(['status' => $status, 'caption' => 'Mountain unavailable']);
    }
    PhotoPost::factory()->for($approved)->create(['status' => PhotoPostStatus::Ready, 'caption' => null]);
    PhotoPost::factory()->for($approved)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'Unrelated',
        'location' => 'Mountain',
        'raw_key' => 'location/Mountain',
        'output_key' => 'metadata/Mountain',
    ]);

    $response = $this->actingAs($viewer)->get(route('people.index', ['q' => '  mOuNtAiN  ']));
    $response->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
        ->where('q', 'mOuNtAiN')
        ->where('people.data', [['id' => $approved->id, 'name' => 'Mountain author']])
        ->has('photos.data', 2)
        ->where('photos.data.0', [
            'id' => $visible->id,
            'caption' => '<b>Mountain afternoon</b>',
            'location' => 'Mountain',
            'createdAt' => $visible->created_at->toIso8601String(),
            'author' => ['id' => $approved->id, 'name' => 'Mountain author'],
            'mediaUrl' => route('photo-posts.media', $visible),
            'detailUrl' => route('photo-posts.show', $visible),
            'likeState' => ['liked' => false, 'revision' => 0],
            'saveState' => ['saved' => false, 'revision' => 0],
        ])
        ->where('photos.data.1.id', $own->id)
        ->missing('photos.total')
        ->missing('photos.data.0.raw_key')
        ->missing('photos.data.0.author.email'));
});

it('treats wildcard escape and SQL characters as literal caption text', function (string $query, string $caption) {
    $viewer = User::factory()->create();
    $match = PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready, 'caption' => $caption]);
    PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Ordinary caption']);

    $this->actingAs($viewer)->get(route('people.index', ['q' => $query]))
        ->assertInertia(fn (Assert $page) => $page->has('photos.data', 1)->where('photos.data.0.id', $match->id));
})->with([
    ['%_', 'Literal %_ caption'],
    ['!!', 'Literal !! caption'],
    ["%' OR 1=1 --", "Literal %' OR 1=1 -- caption"],
]);

it('paginates twelve photos newest first with stable ties and allowlisted independent parameters', function () {
    $this->freezeTime();
    $viewer = User::factory()->create();
    User::factory()->count(13)->create(['name' => 'Matching member']);
    $posts = PhotoPost::factory()->count(13)->for($viewer)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Matching caption']);
    $newest = PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Matching caption', 'created_at' => now()->addMinute()]);

    $first = $this->actingAs($viewer)->get(route('people.index', ['q' => 'Matching', 'page' => 2, 'extra' => 'ignored']));
    $first->assertInertia(fn (Assert $page) => $page
        ->has('photos.data', 12)->where('photos.data.0.id', $newest->id)
        ->where('photos.data.1.id', $posts[12]->id)->where('photos.data.11.id', $posts[2]->id)
        ->where('photos.prev_page_url', null)->missing('photos.total'));
    $nextUrl = $first->inertiaProps('photos.next_page_url');
    parse_str(parse_url($nextUrl, PHP_URL_QUERY), $parameters);
    expect(array_keys($parameters))->toBe(['q', 'page', 'photosCursor']);
    expect($parameters['q'])->toBe('Matching');
    expect($parameters['page'])->toBe('2');

    $second = $this->get($nextUrl);
    $second->assertInertia(fn (Assert $page) => $page->has('photos.data', 2)
        ->where('photos.data.0.id', $posts[1]->id)->where('photos.data.1.id', $posts[0]->id)
        ->where('photos.next_page_url', null));
    $peoplePreviousUrl = $second->inertiaProps('people.prev_page_url');
    parse_str(parse_url($peoplePreviousUrl, PHP_URL_QUERY), $peopleParameters);
    expect($peopleParameters)->toBe(['q' => 'Matching', 'photosCursor' => $parameters['photosCursor'], 'page' => '1']);
    $this->get($peoplePreviousUrl)->assertInertia(fn (Assert $page) => $page
        ->has('people.data', 12)->has('photos.data', 2)->where('photos.data.0.id', $posts[1]->id));
    $this->get($second->inertiaProps('photos.prev_page_url'))->assertInertia(fn (Assert $page) => $page
        ->has('photos.data', 12)->where('photos.data.0.id', $newest->id));
    $this->get(route('people.index', ['q' => 'Unmatched']))->assertInertia(fn (Assert $page) => $page
        ->where('photos.data', [])->where('photos.prev_page_url', null)->where('photos.next_page_url', null));
});

it('keeps only the viewer like and save states across searches by different members', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Shared caption']);
    PhotoLike::query()->create(['user_id' => $owner->id, 'photo_post_id' => $post->id, 'liked' => true, 'revision' => 3]);
    PhotoSave::query()->create(['user_id' => $owner->id, 'photo_post_id' => $post->id, 'is_saved' => true, 'revision' => 4, 'last_request_id' => fake()->uuid()]);
    PhotoLike::query()->create(['user_id' => $viewer->id, 'photo_post_id' => $post->id, 'liked' => false, 'revision' => 8]);
    PhotoSave::query()->create(['user_id' => $viewer->id, 'photo_post_id' => $post->id, 'is_saved' => false, 'revision' => 9, 'last_request_id' => fake()->uuid()]);
    $url = route('people.index', ['q' => 'Shared']);

    $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('photos.data.0.likeState', ['liked' => true, 'revision' => 3])
        ->where('photos.data.0.saveState', ['saved' => true, 'revision' => 4]));
    $this->actingAs($viewer)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('photos.data.0.likeState', ['liked' => false, 'revision' => 8])
        ->where('photos.data.0.saveState', ['saved' => false, 'revision' => 9]));
    $this->actingAs(User::factory()->create())->get($url)->assertInertia(fn (Assert $page) => $page->where('photos.data', []));
});

it('rechecks revoked access on the next search page and copied media detail and action URLs', function () {
    $this->freezeTime();
    $follow = Follow::factory()->approved()->create();
    $posts = PhotoPost::factory()->count(13)->for($follow->followed)->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Revoked canary']);
    $post = $posts->last();
    Storage::fake('photo_posts')->put($post->output_key, 'synthetic photo bytes');
    $first = $this->actingAs($follow->follower)->get(route('people.index', ['q' => 'Revoked']));
    $first->assertInertia(fn (Assert $page) => $page->has('photos.data', 12));
    $nextUrl = $first->inertiaProps('photos.next_page_url');
    $this->get(route('photo-posts.media', $post))->assertOk();
    $follow->delete();

    $this->get($nextUrl)->assertInertia(fn (Assert $page) => $page->where('photos.data', []));
    $this->get(route('photo-posts.media', $post))->assertNotFound();
    $this->head(route('photo-posts.media', $post))->assertNotFound();
    $this->getJson(route('photo-posts.show', $post))->assertNotFound();
    $this->postJson(route('photo-likes.store', $post), ['liked' => true, 'revision' => 0, 'request_id' => fake()->uuid()])->assertNotFound();
    $this->postJson(route('photo-saves.update', $post), ['saved' => true, 'revision' => 0, 'request_id' => fake()->uuid()])->assertNotFound();
});

it('resets malformed photo cursors without passing invalid values to the database', function (mixed $cursor) {
    $this->actingAs(User::factory()->create())
        ->get(route('people.index', ['q' => 'Caption', 'photosCursor' => $cursor, 'page' => 2, 'extra' => 'ignored']))
        ->assertRedirectToRoute('people.index', ['q' => 'Caption'])
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'garbage' => 'not-a-cursor',
    'array' => [['invalid']],
    'oversized' => str_repeat('A', 513),
    'missing fields' => (new Cursor(['id' => 1]))->encode(),
    'array timestamp' => (new Cursor(['created_at' => [], 'id' => 1]))->encode(),
    'invalid date' => (new Cursor(['created_at' => '2026-02-30 00:00:00', 'id' => 1]))->encode(),
    'unparseable date' => (new Cursor(['created_at' => 'tomorrow', 'id' => 1]))->encode(),
    'null byte date' => (new Cursor(['created_at' => "2026-01-01 00:00:00\0", 'id' => 1]))->encode(),
    'year zero' => (new Cursor(['created_at' => '0000-01-01 00:00:00', 'id' => 1]))->encode(),
    'array id' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => []]))->encode(),
    'negative id' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => -1]))->encode(),
    'fractional id' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => 1.5]))->encode(),
    'overflow id' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => '9223372036854775808']))->encode(),
    'scientific id' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => '1e3']))->encode(),
    'array direction' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => 1], []))->encode(),
    'extra field' => (new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => 1, 'extra' => 'ignored']))->encode(),
]);

it('does not expand visibility with a forged otherwise valid cursor', function () {
    $viewer = User::factory()->create();
    $hidden = PhotoPost::factory()->create(['status' => PhotoPostStatus::Ready, 'caption' => 'Hidden caption']);
    $cursor = new Cursor(['created_at' => '9999-12-31 23:59:59', 'id' => $hidden->id]);

    $this->actingAs($viewer)->get(route('people.index', ['q' => 'Hidden', 'photosCursor' => $cursor->encode()]))
        ->assertInertia(fn (Assert $page) => $page->where('photos.data', []));
});
