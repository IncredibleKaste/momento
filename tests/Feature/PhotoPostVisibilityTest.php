<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoAccess;

it('selects only ready posts owned by the viewer or an approved followed author', function () {
    $viewer = User::factory()->create();
    $approved = User::factory()->create();
    $pending = User::factory()->create();
    $reverse = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $approved->id]);
    Follow::factory()->create(['follower_id' => $viewer->id, 'followed_id' => $pending->id]);
    Follow::factory()->approved()->create(['follower_id' => $reverse->id, 'followed_id' => $viewer->id]);
    $ownPost = PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready]);
    $approvedPost = PhotoPost::factory()->for($approved)->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->for($pending)->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->for($reverse)->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->create(['status' => PhotoPostStatus::Ready]);
    PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Queued]);
    PhotoPost::factory()->for($approved)->create(['status' => PhotoPostStatus::Failed]);

    $ids = app(PhotoAccess::class)->visiblePosts($viewer)->orderBy('photo_posts.id')->pluck('photo_posts.id')->all();

    expect($ids)->toBe([$ownPost->id, $approvedPost->id]);
});

it('rechecks a removed approval when a prepared visibility query is reused', function () {
    $follow = Follow::factory()->approved()->create();
    $post = PhotoPost::factory()->for($follow->followed)->create(['status' => PhotoPostStatus::Ready]);
    $access = app(PhotoAccess::class);
    $query = $access->visiblePosts($follow->follower);
    expect($query->pluck('photo_posts.id')->all())->toBe([$post->id]);

    $follow->delete();

    expect($query->pluck('photo_posts.id')->all())->toBe([]);
    expect($access->allows($follow->follower, $follow->followed))->toBeFalse();
});

it('does not use stale verified user objects to grant current photo access', function (string $changedMember) {
    $follow = Follow::factory()->approved()->create();
    $viewer = $follow->follower;
    $author = $follow->followed;
    $post = PhotoPost::factory()->for($author)->create(['status' => PhotoPostStatus::Ready]);
    $access = app(PhotoAccess::class);
    $query = $access->visiblePosts($viewer);
    expect($query->pluck('photo_posts.id')->all())->toBe([$post->id]);

    User::query()->whereKey($changedMember === 'viewer' ? $viewer->id : $author->id)
        ->update(['email_verified_at' => null]);

    expect($query->pluck('photo_posts.id')->all())->toBe([]);
    expect($access->allows($viewer, $author))->toBeFalse();
})->with(['viewer', 'author']);

it('does not grant an unverified viewer a query over private posts', function () {
    $viewer = User::factory()->unverified()->create();
    PhotoPost::factory()->for($viewer)->create(['status' => PhotoPostStatus::Ready]);

    $posts = app(PhotoAccess::class)->visiblePosts($viewer)->get();

    expect($posts)->toBeEmpty();
});

it('serializes only public post fields even when its author relation is loaded', function () {
    $post = PhotoPost::factory()->create(['status' => PhotoPostStatus::Ready]);
    $keys = array_keys($post->load('user')->toArray());
    sort($keys);

    expect($keys)->toBe(['caption', 'created_at', 'id', 'status', 'updated_at', 'user_id']);
});
