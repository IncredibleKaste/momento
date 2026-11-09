<?php

namespace App\Models;

use App\Enums\PhotoPostStatus;
use Database\Factories\PhotoPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string $attempt_token
 * @property PhotoPostStatus $status
 * @property string|null $caption
 * @property string $raw_key
 * @property string $output_key
 * @property int $input_bytes
 * @property int $staging_bytes_charged
 * @property int $processed_bytes_charged
 * @property string|null $failure_code
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $cleanup_due_at
 * @property Carbon|null $cleanup_completed_at
 */
#[Fillable([
    'user_id', 'idempotency_key', 'request_fingerprint', 'attempt_token', 'status', 'caption',
    'raw_key', 'output_key', 'input_bytes', 'staging_bytes_charged', 'processed_bytes_charged',
    'failure_code', 'processing_started_at', 'cancel_requested_at', 'cleanup_due_at', 'cleanup_completed_at',
])]
#[Visible(['id', 'user_id', 'status', 'caption', 'created_at', 'updated_at'])]
class PhotoPost extends Model
{
    /** @use HasFactory<PhotoPostFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PhotoPostStatus::class,
            'input_bytes' => 'integer',
            'staging_bytes_charged' => 'integer',
            'processed_bytes_charged' => 'integer',
            'processing_started_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'cleanup_due_at' => 'immutable_datetime',
            'cleanup_completed_at' => 'immutable_datetime',
        ];
    }
}
