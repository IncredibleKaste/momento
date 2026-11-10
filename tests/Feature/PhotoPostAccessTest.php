<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('serves a ready photo privately to its author and a currently approved follower', function () {
    $owner = User::factory()->create();
    $follower = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $follower->id, 'followed_id' => $owner->id]);
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $disk = Storage::fake('photo_posts');
    $disk->put($post->output_key, 'private processed photo bytes');

    foreach ([$owner, $follower] as $viewer) {
        $this->actingAs($viewer)->get(route('photo-posts.media', $post))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->head(route('photo-posts.media', $post))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    $disk->assertExists($post->output_key);
});

it('rechecks approval for copied photo URLs including head and range requests', function () {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->followed)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'A private caption canary',
        'location' => 'A private location canary',
    ]);
    $disk = Storage::fake('photo_posts');
    $disk->put($post->output_key, 'private processed photo bytes');
    $url = route('photo-posts.media', $post);
    $this->actingAs($follow->follower)->get($url)->assertOk();

    $follow->delete();

    $this->get($url)->assertNotFound()->assertDontSee('private processed photo bytes');
    $this->head($url)->assertNotFound();
    $this->withHeader('Range', 'bytes=0-3')->get($url)
        ->assertNotFound()->assertDontSee('private processed photo bytes');
    $this->withoutHeader('Range')->getJson(route('photo-posts.show', $post))
        ->assertNotFound()->assertDontSee('A private caption canary')->assertDontSee('A private location canary');
    $disk->assertExists($post->output_key);
});

it('does not treat the opposite directional approval as photo permission', function () {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->follower)->create(['status' => PhotoPostStatus::Ready]);
    $disk = Storage::fake('photo_posts');
    $disk->put($post->output_key, 'private processed photo bytes');

    $this->actingAs($follow->followed)->get(route('photo-posts.media', $post))
        ->assertNotFound()->assertDontSee('private processed photo bytes');
    $this->getJson(route('photo-posts.show', $post))->assertNotFound()->assertJsonMissingPath('photoPost');

    $disk->assertExists($post->output_key);
});

it('keeps unfinished photo captions and status owner-only even after follow approval', function (PhotoPostStatus $status) {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->followed)->create([
        'status' => $status,
        'caption' => 'Private unfinished caption',
        'location' => 'Private unfinished location',
    ]);

    $this->actingAs($follow->followed)->get(route('photo-posts.show', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->component('photo-posts/show')
            ->where('photoPost.id', $post->id)
            ->where('photoPost.status', $status->value)
            ->where('photoPost.caption', 'Private unfinished caption')
            ->where('photoPost.location', 'Private unfinished location')
            ->missing('photoPost.raw_key')
            ->missing('photoPost.output_key')
            ->missing('photoPost.request_fingerprint')
            ->missing('photoPost.attempt_token')
            ->missing('photoPost.idempotency_key'));
    $this->actingAs($follow->follower)->getJson(route('photo-posts.show', $post))
        ->assertNotFound()->assertDontSee('Private unfinished caption')->assertDontSee('Private unfinished location');
    $this->get(route('photo-posts.media', $post))->assertNotFound();
})->with([
    'queued' => PhotoPostStatus::Queued,
    'failed' => PhotoPostStatus::Failed,
]);

it('requires a verified session for photo receipts and media', function () {
    $post = PhotoPost::factory()->create(['status' => PhotoPostStatus::Ready]);

    $this->get(route('photo-posts.show', $post))->assertRedirectToRoute('login');
    $this->head(route('photo-posts.media', $post))->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('photo-posts.show', $post))->assertRedirectToRoute('verification.notice');
    $this->head(route('photo-posts.media', $post))->assertRedirectToRoute('verification.notice');
});
