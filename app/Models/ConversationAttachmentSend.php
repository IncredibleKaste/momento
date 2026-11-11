<?php

namespace App\Models;

use Database\Factories\ConversationAttachmentSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property string $nonce
 * @property string $request_fingerprint
 * @property string $attempt_token
 * @property string $status
 * @property string|null $body
 * @property array<int, array<string, mixed>> $files
 * @property int $reserved_bytes
 * @property int $charged_bytes
 * @property int|null $message_id
 * @property string|null $failure_code
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $cleanup_due_at
 * @property Carbon|null $cleanup_completed_at
 */
#[Fillable([
    'conversation_id', 'sender_id', 'nonce', 'request_fingerprint', 'attempt_token', 'status', 'body', 'files',
    'reserved_bytes', 'charged_bytes', 'message_id', 'failure_code', 'processing_started_at', 'cleanup_due_at',
    'cleanup_completed_at',
])]
class ConversationAttachmentSend extends Model
{
    /** @use HasFactory<ConversationAttachmentSendFactory> */
    use HasFactory;

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<ConversationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'files' => 'array',
            'reserved_bytes' => 'integer',
            'charged_bytes' => 'integer',
            'processing_started_at' => 'immutable_datetime',
            'cleanup_due_at' => 'immutable_datetime',
            'cleanup_completed_at' => 'immutable_datetime',
        ];
    }
}
