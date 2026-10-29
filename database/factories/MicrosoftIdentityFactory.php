<?php

namespace Database\Factories;

use App\Models\MicrosoftIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MicrosoftIdentity>
 */
class MicrosoftIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['password' => null]),
            'tenant_id' => fake()->uuid(),
            'object_id' => fake()->uuid(),
            'subject' => fake()->uuid(),
        ];
    }
}
