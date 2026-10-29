<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('unknown browser routes return a recovery page for guests', function () {
    $this->get('/missing-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('not-found')->where('auth.user', null));
});

test('unknown browser routes return a recovery page for members', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/missing-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('not-found')->where('auth.user.id', $user->id));
});

test('unknown JSON routes keep a JSON 404 response', function () {
    $this->getJson('/missing-page')->assertNotFound()->assertJsonMissingPath('component');
});

test('unverified users cannot enter the member home', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});
