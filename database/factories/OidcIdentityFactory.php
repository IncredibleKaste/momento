<?php

namespace Database\Factories;

use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OidcIdentity> */
class OidcIdentityFactory extends Factory
{
    protected $model = OidcIdentity::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->passwordless(),
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject' => fake()->uuid(),
            'stable_subject' => null,
        ];
    }
}
