<?php

use App\Models\Follow;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('requires approval and revokes the next read when either participant removes a follow', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Route::middleware(['web', 'auth', 'verified'])->get('/test-photo/{author}', function (User $author, PhotoAccess $access) {
        abort_unless($access->allows(request()->user(), $author), 404);

        return response()->json(['caption' => 'A fictional private photo']);
    });

    $this->actingAs($alice)->get('/test-photo/'.$bob->id)->assertNotFound();
    $this->post(route('follows.store', $bob), ['follower_id' => $bob->id, 'status' => 'approved'])->assertRedirectToRoute('following.index');
    $follow = Follow::sole();
    expect($follow->follower_id)->toBe($alice->id);
    expect($follow->status)->toBe('pending');
    $this->post(route('follows.store', $bob))->assertRedirect();
    expect(Follow::sole()->id)->toBe($follow->id);
    $this->get('/test-photo/'.$bob->id)->assertNotFound();
    $this->actingAs($bob)->get('/test-photo/'.$bob->id)->assertJson(['caption' => 'A fictional private photo']);
    $this->patch(route('follows.approve', $follow))->assertRedirectToRoute('following.index');
    $this->actingAs($alice)->get('/test-photo/'.$bob->id)->assertJson(['caption' => 'A fictional private photo']);
    $this->actingAs($bob)->get('/test-photo/'.$alice->id)->assertNotFound();
    $this->delete(route('follows.destroy', $follow))->assertRedirect();
    $this->actingAs($alice)->get('/test-photo/'.$bob->id)->assertNotFound();
    $this->post(route('follows.store', $bob))->assertRedirect();
    $next = Follow::sole();
    expect($next->id)->not->toBe($follow->id);
    expect($next->status)->toBe('pending');
    $this->actingAs($bob)->patch(route('follows.approve', $follow))->assertNotFound();
    expect($next->fresh()->status)->toBe('pending');
    $this->patch(route('follows.approve', $next))->assertRedirect();
    $this->actingAs($alice)->delete(route('follows.destroy', $next))->assertRedirect();
    $this->get('/test-photo/'.$bob->id)->assertNotFound();
    $this->assertDatabaseCount('follows', 0);
});

it('enforces each participant role for approval and removal', function (string $role, string $action, string $state) {
    $follow = Follow::factory()->create(['status' => $state]);
    $actor = match ($role) {
        'follower' => $follow->follower,
        'owner' => $follow->followed,
        default => User::factory()->create(),
    };
    $allowed = $action === 'approve' ? $role === 'owner' : $role !== 'other';
    $response = $this->actingAs($actor)->{$action === 'approve' ? 'patch' : 'delete'}(route('follows.'.$action, $follow));
    if ($allowed) {
        $response->assertRedirectToRoute('following.index');
        if ($action === 'approve') {
            expect($follow->fresh()->status)->toBe('approved');
        } else {
            $this->assertModelMissing($follow);
        }
    } else {
        $response->assertNotFound();
        expect($follow->fresh()->status)->toBe($state);
    }
})->with(['follower', 'owner', 'other'])->with(['approve', 'destroy'])->with(['pending', 'approved']);

it('allows decline and cancellation while fresh requests always need approval', function () {
    $follow = Follow::factory()->create();
    $this->actingAs($follow->followed)->delete(route('follows.destroy', $follow))->assertRedirect();
    $this->assertModelMissing($follow);
    $this->actingAs($follow->follower)->post(route('follows.store', $follow->followed))->assertRedirect();
    $pending = Follow::sole();
    expect($pending->status)->toBe('pending');
    $this->delete(route('follows.destroy', $pending))->assertRedirect();
    $this->delete(route('follows.destroy', $pending))->assertNotFound();
    $this->assertDatabaseCount('follows', 0);
});

it('requires authenticated verified membership for every follow surface', function (bool $guest) {
    $follow = Follow::factory()->create();
    if (! $guest) {
        $this->actingAs(User::factory()->unverified()->create());
    }
    $destination = $guest ? 'login' : 'verification.notice';
    $this->get(route('following.index'))->assertRedirectToRoute($destination);
    $this->get(route('members.show', $follow->followed))->assertRedirectToRoute($destination);
    $this->post(route('follows.store', $follow->followed))->assertRedirectToRoute($destination);
    $this->patch(route('follows.approve', $follow))->assertRedirectToRoute($destination);
    $this->delete(route('follows.destroy', $follow))->assertRedirectToRoute($destination);
    expect($follow->fresh()->status)->toBe('pending');
})->with([true, false]);

it('rejects self requests and missing or unverified target accounts', function () {
    $actor = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($actor)->post(route('follows.store', $actor))->assertSessionHasErrors(['follow' => 'You cannot follow yourself.']);
    $this->post(route('follows.store', $unverified))->assertNotFound();
    $this->get(route('members.show', $unverified))->assertNotFound();
    $this->post(route('follows.store', 999999))->assertNotFound();
    $this->get(route('members.show', 999999))->assertNotFound();
    $this->assertDatabaseCount('follows', 0);
});

it('requires CSRF for request approval and removal', function () {
    $follow = Follow::factory()->create();
    app()->instance('env', 'local');
    $this->actingAs($follow->followed)->patch(route('follows.approve', $follow))->assertStatus(419);
    $this->delete(route('follows.destroy', $follow))->assertStatus(419);
    $this->actingAs($follow->follower)->post(route('follows.store', $follow->followed))->assertStatus(419);
    expect($follow->fresh()->status)->toBe('pending');
});

it('limits repeated requests without grouping members behind the same address', function () {
    $actor = User::factory()->create();
    $member = User::factory()->create();
    $this->actingAs($actor);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('follows.store', $member))->assertRedirect();
    }
    $this->post(route('follows.store', $member))->assertTooManyRequests()->assertHeader('Retry-After');
    $this->actingAs(User::factory()->create())->post(route('follows.store', $member))->assertRedirect();
    $this->assertDatabaseCount('follows', 2);
});

it('limits new requests across targets for the same account', function () {
    $actor = User::factory()->create();
    $members = User::factory()->count(21)->create();
    $this->actingAs($actor);
    foreach ($members->take(20) as $member) {
        $this->post(route('follows.store', $member))->assertRedirect();
    }
    $this->post(route('follows.store', $members->last()))->assertTooManyRequests();
    $this->assertDatabaseCount('follows', 20);
});

it('projects only related member names and route identity with bounded pagination', function () {
    $viewer = User::factory()->create();
    $incoming = Follow::factory()->count(13)->create(['followed_id' => $viewer->id]);
    $unrelated = Follow::factory()->create();
    $this->actingAs($viewer)->get(route('following.index'))->assertInertia(fn (Assert $page) => $page
        ->component('following/index')->has('incoming.data', 12)->where('incoming.total', 13)
        ->has('incoming.next_page_url')->where('outgoing.total', 0)->where('followers.total', 0)->where('following.total', 0)
        ->missing('incoming.data.0.member.email')->missing('incoming.data.0.member.password'));
    $this->get(route('following.index', ['incomingPage' => 2]))->assertInertia(fn (Assert $page) => $page->has('incoming.data', 1));
    $member = $incoming->first()->follower;
    $this->get(route('members.show', $member))->assertInertia(fn (Assert $page) => $page
        ->component('members/show')->where('member', ['id' => $member->id, 'name' => $member->name])->where('follow', null));
    expect(app(PhotoAccess::class)->allows($viewer, $unrelated->followed))->toBeFalse();
});

it('does not cache an approval in a reused access service or loaded user relation', function () {
    $follow = Follow::factory()->approved()->create();
    $access = app(PhotoAccess::class);
    $viewer = $follow->follower;
    $author = $follow->followed;
    expect($access->allows($viewer, $author))->toBeTrue();
    $this->actingAs($author)->delete(route('follows.destroy', $follow))->assertRedirect();
    expect($access->allows($viewer, $author))->toBeFalse();
    $viewer->email_verified_at = null;
    expect($access->allows($viewer, $viewer))->toBeFalse();
});

it('rolls back failed approval without granting photo access', function () {
    $follow = Follow::factory()->create();
    Exceptions::fake();
    Event::listen('eloquent.updating: '.Follow::class, fn () => throw new RuntimeException('Simulated write failure.'));
    try {
        $this->actingAs($follow->followed)->patch(route('follows.approve', $follow))->assertServerError();
        expect($follow->fresh()->status)->toBe('pending');
        expect(app(PhotoAccess::class)->allows($follow->follower, $follow->followed))->toBeFalse();
        Exceptions::assertReported(RuntimeException::class);
    } finally {
        Event::forget('eloquent.updating: '.Follow::class);
    }
});

it('enforces one persisted direction even for competing writes outside the action', function () {
    $follow = Follow::factory()->create();
    expect(fn () => DB::transaction(fn () => Follow::factory()->create([
        'follower_id' => $follow->follower_id, 'followed_id' => $follow->followed_id,
    ])))->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('follows', 1);
});

it('keeps a member name as escaped text without exposing private account fields', function () {
    $viewer = User::factory()->create();
    $name = '<script>alert("fictional")</script>';
    $member = User::factory()->create(['name' => $name]);
    $this->actingAs($viewer)->get(route('members.show', $member))
        ->assertDontSee($name, false)
        ->assertInertia(fn (Assert $page) => $page->where('member', ['id' => $member->id, 'name' => $name])
            ->missing('member.email')->missing('member.password')->missing('member.two_factor_secret'));
});

it('still permits revocation when the other member becomes unverified', function () {
    $follow = Follow::factory()->approved()->create();
    $follow->followed->forceFill(['email_verified_at' => null])->save();
    expect(app(PhotoAccess::class)->allows($follow->follower, $follow->followed))->toBeFalse();
    $this->actingAs($follow->follower)->delete(route('follows.destroy', $follow))->assertRedirect();
    $this->assertModelMissing($follow);
});

it('returns a recoverable Inertia error when a follow request is throttled', function () {
    $actor = User::factory()->create();
    $member = User::factory()->create();
    $this->actingAs($actor);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('follows.store', $member))->assertRedirect();
    }
    $this->withHeader('X-Inertia', 'true')->post(route('follows.store', $member))
        ->assertRedirectToRoute('following.index')->assertHeader('Retry-After')
        ->assertSessionHasErrors(['follow' => 'Too many follow changes. Wait a minute, then refresh and try again.']);
    expect(Follow::sole()->status)->toBe('pending');
});
