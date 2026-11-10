<?php

namespace App\Actions;

use App\Models\PhotoComment;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ManagePhotoComments
{
    public const PAGE_SIZE = 30;

    /**
     * @return array{
     *     count: int,
     *     preview: list<array{id: int, author: array{id: int, name: string}, body: string, createdAt: string|null, canRemove: bool}>,
     *     comments: list<array{id: int, author: array{id: int, name: string}, body: string, createdAt: string|null, canRemove: bool}>,
     *     nextAfter: int|null
     * }
     */
    public function page(User $viewer, int $postId, ?int $after): array
    {
        $post = $this->visiblePost($viewer, $postId);
        $comments = PhotoComment::query()->where('photo_post_id', $postId)->whereNotNull('body');
        $count = (clone $comments)->count();
        $preview = (clone $comments)->with('author:id,name')->orderBy('id')->limit(2)->get();
        $page = (clone $comments)->when($after !== null, fn ($query) => $query->where('id', '>', $after))
            ->with('author:id,name')->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $page->count() > self::PAGE_SIZE;
        $items = $page->take(self::PAGE_SIZE)->values();

        return [
            'count' => $count,
            'preview' => array_values($preview->map(fn (PhotoComment $comment): array => $this->project($comment, $viewer, $post->user_id))->all()),
            'comments' => array_values($items->map(fn (PhotoComment $comment): array => $this->project($comment, $viewer, $post->user_id))->all()),
            'nextAfter' => $hasMore ? $items->last()->id : null,
        ];
    }

    public function assertVisible(User $viewer, int $postId): PhotoPost
    {
        return $this->visiblePost($viewer, $postId);
    }

    /** @return array{comment: PhotoComment, postOwnerId: int, replayed: bool} */
    public function add(User $actor, int $postId, string $body, string $submissionKey): array
    {
        $this->visiblePost($actor, $postId);

        $actorAllowed = RateLimiter::attempt('photo-comments:actor:'.$actor->id, 30, fn (): bool => true, 60);
        $postAllowed = RateLimiter::attempt('photo-comments:post:'.$actor->id.':'.$postId, 12, fn (): bool => true, 60);
        if (! $actorAllowed || ! $postAllowed) {
            abort(429, 'Too many comments. Wait a minute, then retry your draft.');
        }

        $fingerprint = hash('sha256', $body);

        return DB::transaction(function () use ($actor, $postId, $body, $submissionKey, $fingerprint): array {
            $post = $this->visiblePost($actor, $postId);

            $existing = PhotoComment::query()->where('photo_post_id', $postId)
                ->where('author_id', $actor->id)->where('submission_key', $submissionKey)->first();

            if ($existing) {
                return $this->replay($existing, $fingerprint, $post->user_id);
            }

            $inserted = DB::table('photo_comments')->insertOrIgnore([
                'photo_post_id' => $postId,
                'author_id' => $actor->id,
                'submission_key' => $submissionKey,
                'body_fingerprint' => $fingerprint,
                'body' => $body,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $comment = PhotoComment::query()->where('photo_post_id', $postId)
                ->where('author_id', $actor->id)->where('submission_key', $submissionKey)->firstOrFail();

            return $inserted === 1
                ? ['comment' => $comment->load('author:id,name'), 'postOwnerId' => $post->user_id, 'replayed' => false]
                : $this->replay($comment, $fingerprint, $post->user_id);
        }, attempts: 3);
    }

    public function remove(User $actor, int $commentId): void
    {
        DB::transaction(function () use ($actor, $commentId): void {
            $comment = PhotoComment::query()->lockForUpdate()->find($commentId);
            if (! $comment || $comment->body === null) {
                return;
            }

            $isAuthor = $comment->author_id === $actor->id;
            $isPostOwner = app(PhotoAccess::class)->visiblePosts($actor)
                ->whereKey($comment->photo_post_id)->where('photo_posts.user_id', $actor->id)->exists();

            if (! $isAuthor && ! $isPostOwner) {
                return;
            }

            $comment->forceFill(['body' => null, 'body_fingerprint' => str_repeat('0', 64), 'removed_at' => now()])->save();
        }, attempts: 3);
    }

    private function visiblePost(User $viewer, int $postId): PhotoPost
    {
        return app(PhotoAccess::class)->visiblePosts($viewer)->whereKey($postId)->firstOrFail();
    }

    /** @return array{comment: PhotoComment, postOwnerId: int, replayed: bool} */
    private function replay(PhotoComment $existing, string $fingerprint, int $postOwnerId): array
    {
        if ($existing->body === null) {
            abort(409, 'This comment was removed and cannot be restored by retrying.');
        }

        if (! hash_equals($existing->body_fingerprint, $fingerprint)) {
            abort(409, 'This submission key already belongs to a different comment.');
        }

        return ['comment' => $existing->load('author:id,name'), 'postOwnerId' => $postOwnerId, 'replayed' => true];
    }

    /** @return array{id: int, author: array{id: int, name: string}, body: string, createdAt: string|null, canRemove: bool} */
    private function project(PhotoComment $comment, User $viewer, int $postOwnerId): array
    {
        return [
            'id' => $comment->id,
            'author' => ['id' => $comment->author->id, 'name' => $comment->author->name],
            'body' => (string) $comment->body,
            'createdAt' => $comment->created_at?->toISOString(),
            'canRemove' => $comment->author_id === $viewer->id || $postOwnerId === $viewer->id,
        ];
    }
}
