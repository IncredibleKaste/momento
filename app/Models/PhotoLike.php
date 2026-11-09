<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $photo_post_id
 * @property bool $liked
 * @property int $revision
 * @property string|null $last_request_id
 * @property bool|null $last_request_liked
 * @property int|null $last_request_expected_revision
 */
#[Fillable([
    'user_id', 'photo_post_id', 'liked', 'revision', 'last_request_id',
    'last_request_liked', 'last_request_expected_revision',
])]
class PhotoLike extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PhotoPost, $this> */
    public function photoPost(): BelongsTo
    {
        return $this->belongsTo(PhotoPost::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'liked' => 'boolean',
            'revision' => 'integer',
            'last_request_liked' => 'boolean',
            'last_request_expected_revision' => 'integer',
        ];
    }
}
