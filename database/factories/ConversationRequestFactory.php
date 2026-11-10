<?php

namespace Database\Factories;

use App\Models\ConversationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversationRequest>
 */
class ConversationRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'recipient_id' => User::factory(),
            'member_one_id' => fn (array $attributes): int => min($attributes['requester_id'], $attributes['recipient_id']),
            'member_two_id' => fn (array $attributes): int => max($attributes['requester_id'], $attributes['recipient_id']),
            'body' => fake()->sentence(),
            'client_nonce' => Str::uuid()->toString(),
            'status' => ConversationRequest::Pending,
            'expires_at' => now()->addDays(30),
        ];
    }
}
