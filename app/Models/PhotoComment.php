<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $photo_post_id
 * @property int $author_id
 * @property string $submission_key
 * @property string $body_fingerprint
 * @property string|null $body
 * @property Carbon|null $removed_at
 */
#[Fillable(['photo_post_id', 'author_id', 'submission_key', 'body_fingerprint', 'body', 'removed_at'])]
class PhotoComment extends Model
{
    /** @return BelongsTo<PhotoPost, $this> */
    public function photoPost(): BelongsTo
    {
        return $this->belongsTo(PhotoPost::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['removed_at' => 'immutable_datetime'];
    }
}
