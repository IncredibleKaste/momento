<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('composes ready photo details with only the current member heart and save state', function () {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->followed)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'A member photo',
    ]);

    $this->actingAs($follow->follower)->postJson(route('photo-likes.store', $post), [
        'liked' => true, 'revision' => 0, 'request_id' => (string) Str::uuid(),
    ])->assertOk();
    $this->post(route('photo-saves.update', $post), [
        'saved' => true, 'revision' => 0, 'request_id' => (string) Str::uuid(),
    ])->assertRedirectToRoute('saved.index');
    $this->postJson(route('photo-comments.store', $post), [
        'body' => 'A plain-text comment', 'submissionKey' => (string) Str::uuid(),
    ])->assertCreated();

    $this->get(route('photo-posts.show', $post))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('photo-posts/show')
            ->where('photoPost.id', $post->id)
            ->where('photoPost.mediaUrl', route('photo-posts.media', $post))
            ->where('detail', [
                'author' => ['id' => $follow->followed->id, 'name' => $follow->followed->name],
                'createdAt' => $post->created_at->toISOString(),
                'likeState' => ['liked' => true, 'revision' => 1],
                'saveState' => ['saved' => true, 'revision' => 1],
            ])
            ->missing('photoPost.raw_key')->missing('photoPost.output_key')
            ->missing('photoPost.request_fingerprint')->missing('photoPost.idempotency_key'));

    $this->getJson(route('photo-comments.index', $post))
        ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('preview.0.body', 'A plain-text comment');
    $this->actingAs($follow->followed)->get(route('photo-posts.show', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.likeState', ['liked' => false, 'revision' => 0])
            ->where('detail.saveState', ['saved' => false, 'revision' => 0]));
});

it('returns retained false-state revisions and omits ready interactions on unfinished receipts', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
    $this->actingAs($owner)->postJson(route('photo-likes.store', $post), [
        'liked' => false, 'revision' => 0, 'request_id' => (string) Str::uuid(),
    ])->assertOk();
    $this->post(route('photo-saves.update', $post), [
        'saved' => false, 'revision' => 0, 'request_id' => (string) Str::uuid(),
    ])->assertRedirect();
    $this->get(route('photo-posts.show', $post))->assertInertia(fn (Assert $page) => $page
        ->where('detail.likeState', ['liked' => false, 'revision' => 1])
        ->where('detail.saveState', ['saved' => false, 'revision' => 1]));

    $pending = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Queued]);
    $this->get(route('photo-posts.show', $pending))->assertInertia(fn (Assert $page) => $page
        ->where('photoPost.status', 'queued')->where('detail', null));
});

it('denies the composed detail and interactions after approval is removed', function () {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->followed)->create([
        'status' => PhotoPostStatus::Ready,
        'caption' => 'A private detail canary',
    ]);
    $this->actingAs($follow->follower)->get(route('photo-posts.show', $post))->assertOk();
    $follow->delete();

    $this->getJson(route('photo-posts.show', $post))->assertNotFound()->assertDontSee('A private detail canary');
    $this->head(route('photo-posts.show', $post))->assertNotFound();
    $this->getJson(route('photo-comments.index', $post))->assertNotFound();
    $this->postJson(route('photo-likes.store', $post), [
        'liked' => true, 'revision' => 0, 'request_id' => (string) Str::uuid(),
    ])->assertNotFound();
    $this->post(route('photo-saves.update', $post), [
        'saved' => true, 'revision' => 0, 'request_id' => (string) Str::uuid(),
    ])->assertNotFound();
});
