<?php

namespace App\Actions;

use App\Models\PhotoLike;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Support\Facades\DB;

class PhotoLikeState
{
    /** @return array{liked: bool, revision: int, conflict: bool} */
    public function handle(
        User $actor,
        PhotoPost $post,
        bool $liked,
        int $expectedRevision,
        string $requestId,
        PhotoAccess $access,
    ): array {
        return DB::transaction(function () use ($actor, $post, $liked, $expectedRevision, $requestId, $access): array {
            abort_unless($access->visiblePosts($actor)->whereKey($post->getKey())->exists(), 404);

            PhotoLike::query()->insertOrIgnore([
                'user_id' => $actor->id,
                'photo_post_id' => $post->id,
                'liked' => false,
                'revision' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = PhotoLike::query()
                ->where('user_id', $actor->id)
                ->where('photo_post_id', $post->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($state->last_request_id === $requestId) {
                if ($state->last_request_liked !== $liked
                    || $state->last_request_expected_revision !== $expectedRevision) {
                    return ['liked' => $state->liked, 'revision' => $state->revision, 'conflict' => true];
                }

                return ['liked' => $state->liked, 'revision' => $state->revision, 'conflict' => false];
            }

            if ($state->revision !== $expectedRevision) {
                return ['liked' => $state->liked, 'revision' => $state->revision, 'conflict' => true];
            }

            $state->forceFill([
                'liked' => $liked,
                'revision' => $state->revision + 1,
                'last_request_id' => $requestId,
                'last_request_liked' => $liked,
                'last_request_expected_revision' => $expectedRevision,
            ])->save();

            return ['liked' => $state->liked, 'revision' => $state->revision, 'conflict' => false];
        }, attempts: 3);
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, array{liked: bool, revision: int}>
     */
    public function forViewer(User $viewer, array $postIds, PhotoAccess $access): array
    {
        $visibleIds = $access->visiblePosts($viewer)->whereKey($postIds)->pluck('photo_posts.id');
        $states = PhotoLike::query()
            ->where('user_id', $viewer->id)
            ->whereIn('photo_post_id', $visibleIds)
            ->get(['photo_post_id', 'liked', 'revision'])
            ->keyBy('photo_post_id');

        return $visibleIds->mapWithKeys(fn (int $postId): array => [
            $postId => [
                'liked' => (bool) ($states->get($postId)->liked ?? false),
                'revision' => (int) ($states->get($postId)->revision ?? 0),
            ],
        ])->all();
    }
}
