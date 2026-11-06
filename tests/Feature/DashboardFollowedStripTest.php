<?php

use App\Models\Follow;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows only the current member’s twelve latest approved verified follows', function () {
    $viewer = User::factory()->create();
    $people = User::factory()->count(13)->create();
    $follows = $people->map(fn (User $person): Follow => Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => $person->id,
    ]));
    $people->last()->update(['name' => '<script>alert("x")</script>']);
    Follow::factory()->create(['follower_id' => $viewer->id]);
    Follow::factory()->approved()->create([
        'follower_id' => $viewer->id,
        'followed_id' => User::factory()->unverified()->create()->id,
    ]);
    Follow::factory()->approved()->create([
        'follower_id' => User::factory()->create()->id,
        'followed_id' => User::factory()->create()->id,
    ]);
    Follow::factory()->approved()->create([
        'follower_id' => User::factory()->create()->id,
        'followed_id' => $viewer->id,
    ]);
    $expected = $follows->slice(1)->reverse()->map(fn (Follow $follow): array => [
        'id' => $follow->followed_id,
        'name' => $follow->followed->name,
    ])->values()->all();

    $this->actingAs($viewer)->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')
            ->where('followedPeople', $expected)
            ->missing('followedPeople.0.email')
            ->missing('followedPeople.0.password'));
});

it('refreshes the strip after a follow is approved or removed', function () {
    $follow = Follow::factory()->create();
    $this->actingAs($follow->follower);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('followedPeople', 0));
    $this->actingAs($follow->followed)->patch(route('follows.approve', $follow))->assertRedirectToRoute('following.index');
    $this->actingAs($follow->follower)->get(route('dashboard'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->where('followedPeople', [[
            'id' => $follow->followed_id,
            'name' => $follow->followed->name,
        ]]));

    $this->actingAs($follow->followed)->delete(route('follows.destroy', $follow))->assertRedirectToRoute('following.index');
    $this->actingAs($follow->follower)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('followedPeople', 0));
});

it('marks the private relationship list response as uncacheable', function () {
    $this->actingAs(User::factory()->create())->get(route('following.index'))
        ->assertHeader('Cache-Control', 'no-store, private');
});
