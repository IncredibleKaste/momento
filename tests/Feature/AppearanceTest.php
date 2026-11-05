<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders only an allowed appearance preference for guest pages', function (?string $cookie, string $expected) {
    if ($cookie !== null) {
        $this->withUnencryptedCookie('appearance', $cookie);
    }

    $this->get(route('login'))->assertOk()
        ->assertViewHas('appearance', $expected)
        ->assertSee('data-appearance="'.$expected.'"', false)
        ->assertDontSee('<script>fictional</script>', false);
})->with([
    [null, 'system'], ['light', 'light'], ['dark', 'dark'], ['system', 'system'],
    ['sepia', 'system'], ['<script>fictional</script>', 'system'],
]);

it('requires verified membership to open appearance settings', function (string $membership) {
    if ($membership !== 'guest') {
        $this->actingAs($membership === 'verified' ? User::factory()->create() : User::factory()->unverified()->create());
    }

    $response = $this->get(route('appearance.edit'));
    if ($membership === 'verified') {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/appearance'));
    } else {
        $response->assertRedirectToRoute($membership === 'guest' ? 'login' : 'verification.notice');
    }
})->with(['guest', 'unverified', 'verified']);
