<?php

namespace Database\Factories;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Follow> */
class FollowFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'follower_id' => User::factory(),
            'followed_id' => User::factory(),
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => 'approved']);
    }
}
