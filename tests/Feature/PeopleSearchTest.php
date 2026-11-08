<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires an authenticated verified member to search', function (bool $guest) {
    if (! $guest) {
        $this->actingAs(User::factory()->unverified()->create());
    }

    $this->get(route('people.index'))
        ->assertRedirectToRoute($guest ? 'login' : 'verification.notice');
})->with([true, false]);

it('searches only other verified members by their public display name', function () {
    $viewer = User::factory()->create(['name' => 'Alicia Viewer']);
    $match = User::factory()->create(['name' => 'Alicia Member']);
    User::factory()->unverified()->create(['name' => 'Alicia Pending']);
    User::factory()->create(['name' => 'Unrelated', 'email' => 'alicia@example.test']);

    $this->actingAs($viewer)->get(route('people.index', ['q' => '  aLiCiA  ']))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/index')
            ->where('q', 'aLiCiA')
            ->where('people.data', [['id' => $match->id, 'name' => 'Alicia Member']])
            ->missing('people.total')
            ->missing('people.data.0.email')
            ->missing('people.data.0.email_verified_at'));
});

it('shows an instructional empty state without listing members before a query', function () {
    $viewer = User::factory()->create();
    User::factory()->count(3)->create();

    $this->actingAs($viewer)->get(route('people.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/index')
            ->where('q', null)
            ->where('people', null));
});

it('treats SQL wildcard and injection characters as literal search text', function (string $query, string $matchingName) {
    $viewer = User::factory()->create();
    $match = User::factory()->create(['name' => $matchingName]);
    User::factory()->create(['name' => 'Ordinary member']);

    $this->actingAs($viewer)->get(route('people.index', ['q' => $query]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.data', [['id' => $match->id, 'name' => $matchingName]]));
})->with([
    'percent and underscore are literal' => ['%_', 'Member %_ marker'],
    'escape marker is literal' => ['!!', 'Member !! marker'],
    'SQL syntax remains bound text' => ["%' OR 1=1 --", "Member %' OR 1=1 -- marker"],
]);

it('paginates by name and id and preserves only the search query', function () {
    $viewer = User::factory()->create();
    $members = User::factory()->count(13)->sequence(
        ['name' => 'Zoe Member'],
        ['name' => 'Amy Member'],
        ['name' => 'Mona Member'],
        ['name' => 'Ava Member'],
        ['name' => 'Bella Member'],
        ['name' => 'Nina Member'],
        ['name' => 'Cam Member'],
        ['name' => 'Amy Member'],
        ['name' => 'Eve Member'],
        ['name' => 'Lia Member'],
        ['name' => 'Omar Member'],
        ['name' => 'Tia Member'],
        ['name' => 'Uma Member'],
    )->create();
    $orderedMembers = [
        $members[1], $members[7], $members[3], $members[4], $members[6], $members[8],
        $members[9], $members[2], $members[5], $members[10], $members[11], $members[12], $members[0],
    ];

    $this->actingAs($viewer)->get(route('people.index', ['q' => 'Member', 'extra' => 'ignored']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.data', collect($orderedMembers)->take(12)->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
            ])->all())
            ->where('people.per_page', 12)
            ->where('people.next_page_url', route('people.index', ['q' => 'Member', 'page' => 2])));

    $this->get(route('people.index', ['q' => 'Member', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.data', [['id' => $members[0]->id, 'name' => 'Zoe Member']])
            ->where('people.prev_page_url', route('people.index', ['q' => 'Member', 'page' => 1])));
});

it('rejects queries outside the supported type and length', function (mixed $query, string $message) {
    $this->actingAs(User::factory()->create())
        ->from(route('people.index'))
        ->get(route('people.index', ['q' => $query]))
        ->assertRedirect(route('people.index'))
        ->assertSessionHasErrors(['q' => $message]);
})->with([
    'too short' => ['A', 'The display name field must be at least 2 characters.'],
    'too long' => [str_repeat('A', 81), 'The display name field must not be greater than 80 characters.'],
    'array value' => [['Alicia', 'Member'], 'The display name field must be a string.'],
]);

it('rate limits search requests per verified member', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);
    $url = route('people.index', ['q' => 'Alicia']);

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertTooManyRequests();
    $this->actingAs(User::factory()->create())->get($url)->assertOk();
});
