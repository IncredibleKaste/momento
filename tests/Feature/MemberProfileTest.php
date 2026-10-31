<?php

use App\Models\Follow;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps profile identity stable after a name change and excludes private fields', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create();
    $url = route('members.show', $member);
    $member->update(['name' => 'A changed display name']);

    $this->actingAs($viewer)->get($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('members/show')
            ->where('member', ['id' => $member->id, 'name' => 'A changed display name'])
            ->where('follow', null)->missing('photos')->missing('captions'));
});

it('reads only the current outgoing relationship on every profile request', function () {
    $follow = Follow::factory()->create();
    Follow::factory()->approved()->create([
        'follower_id' => $follow->followed_id,
        'followed_id' => $follow->follower_id,
    ]);
    $url = route('members.show', $follow->followed);
    $this->actingAs($follow->follower);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('follow', ['id' => $follow->id, 'status' => 'pending']));
    $follow->update(['status' => 'approved']);
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->get($url)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('props.follow', ['id' => $follow->id, 'status' => 'approved']);
    $follow->delete();
    $this->withoutHeader('X-Inertia')->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('member.id', $follow->followed_id)->where('follow', null));
    $this->head($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('lets an owner open the same minimal profile without a self follow', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('members.show', $owner))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('member', ['id' => $owner->id, 'name' => $owner->name])
            ->where('follow', null));
});

it('does not expose profiles to guests or unverified members', function (bool $guest) {
    $member = User::factory()->create();
    if (! $guest) {
        $this->actingAs(User::factory()->unverified()->create());
    }

    $this->get(route('members.show', $member))
        ->assertRedirectToRoute($guest ? 'login' : 'verification.notice');
    $this->head(route('members.show', $member))
        ->assertRedirectToRoute($guest ? 'login' : 'verification.notice');
})->with([true, false]);

it('returns the same recovery page for unavailable member identities', function (string $state) {
    $viewer = User::factory()->create();
    $member = User::factory()->create();
    $id = $member->id;
    if ($state === 'unverified') {
        $member->forceFill(['email_verified_at' => null])->save();
    } else {
        $member->delete();
    }

    $this->actingAs($viewer)->get(route('members.show', $id))->assertNotFound()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('not-found')->where('auth.user.id', $viewer->id)->missing('member')->missing('follow'));
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
        ->get(route('members.show', $id))->assertNotFound()
        ->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'not-found')->assertJsonMissingPath('props.member');
})->with(['unverified', 'deleted']);

it('keeps JSON missing-profile responses free of a member projection', function () {
    $this->actingAs(User::factory()->create())->getJson(route('members.show', 999999))
        ->assertNotFound()->assertJsonMissingPath('member')->assertJsonMissingPath('component');
});
