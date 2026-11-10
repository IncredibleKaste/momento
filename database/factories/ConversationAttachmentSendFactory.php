<?php

namespace Database\Factories;

use App\Actions\ManageConversation;
use App\Models\ConversationAttachmentSend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversationAttachmentSend> */
class ConversationAttachmentSendFactory extends Factory
{
    protected $model = ConversationAttachmentSend::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $token = (string) Str::uuid();

        return [
            'sender_id' => User::factory(),
            'conversation_id' => function (array $attributes): int {
                $member = User::factory()->create();

                return app(ManageConversation::class)->createPair($attributes['sender_id'], $member->id)->id;
            },
            'nonce' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'attempt_token' => $token,
            'status' => 'staging',
            'body' => null,
            'files' => [],
            'reserved_bytes' => 1,
            'charged_bytes' => 0,
        ];
    }
}
