<?php

namespace App\Actions;

use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagePhotoSaves
{
    public function set(User $owner, PhotoPost $post, bool $saved, int $revision, string $requestId): void
    {
        DB::transaction(function () use ($owner, $post, $saved, $revision, $requestId): void {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();

            $record = PhotoSave::query()->where('user_id', $owner->id)
                ->where('photo_post_id', $post->id)->lockForUpdate()->first();

            if ($record?->last_request_id === $requestId) {
                if ($record->is_saved !== $saved || $revision !== ($record->revision - 1)) {
                    throw ValidationException::withMessages([
                        'save' => 'This request was already used for a different save. Refresh the page and try again.',
                    ]);
                }

                return;
            }

            if (($record->revision ?? 0) !== $revision) {
                throw ValidationException::withMessages([
                    'save' => 'This save changed elsewhere. Refresh the page and try again.',
                ]);
            }

            $visible = app(PhotoAccess::class)->visiblePosts($owner)->whereKey($post->id)->exists();
            if ($saved && ! $visible) {
                abort(404);
            }

            $order = $record->saved_order ?? 0;
            if ($saved && ! ($record->is_saved ?? false)) {
                $order = (int) PhotoSave::query()->where('user_id', $owner->id)->max('saved_order') + 1;
            }

            if ($record === null) {
                PhotoSave::query()->create([
                    'user_id' => $owner->id,
                    'photo_post_id' => $post->id,
                    'is_saved' => $saved,
                    'revision' => 1,
                    'last_request_id' => $requestId,
                    'saved_order' => $order,
                ]);

                return;
            }

            $record->update([
                'is_saved' => $saved,
                'revision' => $record->revision + 1,
                'last_request_id' => $requestId,
                'saved_order' => $order,
            ]);
        });
    }
}
