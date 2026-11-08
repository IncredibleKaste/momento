<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('serves the public welcome entry to guests and members without photo props', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('auth.user', null)
        ->missing('photos'));

    $user = User::factory()->create();

    $this->actingAs($user)->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('auth.user.id', $user->id)
        ->missing('photos'));
});
