<?php

namespace Database\Factories;

use App\Enums\PhotoPostStatus;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PhotoPost> */
class PhotoPostFactory extends Factory
{
    protected $model = PhotoPost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $attemptToken = (string) Str::uuid();

        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'attempt_token' => $attemptToken,
            'status' => PhotoPostStatus::Staging,
            'caption' => null,
            'raw_key' => "{$attemptToken}/original",
            'output_key' => "{$attemptToken}/processed.webp",
            'input_bytes' => 1,
            'staging_bytes_charged' => 1,
            'processed_bytes_charged' => 0,
            'cleanup_completed_at' => null,
        ];
    }
}
