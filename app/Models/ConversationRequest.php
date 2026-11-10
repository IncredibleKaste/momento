<?php

namespace App\Models;

use Database\Factories\ConversationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $requester_id
 * @property int $recipient_id
 * @property int $member_one_id
 * @property int $member_two_id
 * @property string $body
 * @property string $client_nonce
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $reopened_at
 */
#[Fillable(['requester_id', 'recipient_id', 'member_one_id', 'member_two_id', 'body', 'client_nonce', 'status', 'expires_at', 'responded_at', 'stopped_at', 'reopened_at'])]
class ConversationRequest extends Model
{
    /** @use HasFactory<ConversationRequestFactory> */
    use HasFactory;

    public const Pending = 'pending';

    public const Accepted = 'accepted';

    public const Declined = 'declined';

    public const Stopped = 'stopped';

    public const Expired = 'expired';

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @param Builder<ConversationRequest> $query */
    public function scopeForPair(Builder $query, int $first, int $second): void
    {
        $query->where('member_one_id', min($first, $second))
            ->where('member_two_id', max($first, $second));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'stopped_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
