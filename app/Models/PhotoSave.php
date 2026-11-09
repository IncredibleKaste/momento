<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $photo_post_id
 * @property bool $is_saved
 * @property int $revision
 * @property string $last_request_id
 * @property int $saved_order
 */
#[Fillable(['user_id', 'photo_post_id', 'is_saved', 'revision', 'last_request_id', 'saved_order'])]
#[Visible(['id', 'user_id', 'photo_post_id', 'is_saved', 'revision'])]
class PhotoSave extends Model
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
            'is_saved' => 'boolean',
            'revision' => 'integer',
            'saved_order' => 'integer',
        ];
    }
}
