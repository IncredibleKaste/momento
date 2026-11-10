<?php

namespace App\Services;

use App\Enums\PhotoPostStatus;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PhotoAccess
{
    public function allows(User $viewer, User $author): bool
    {
        if (! $viewer->hasVerifiedEmail() || ! $author->hasVerifiedEmail()) {
            return false;
        }

        return $this->visibleAuthors($viewer)->whereKey($author->getKey())->exists();
    }

    /** @return Builder<PhotoPost> */
    public function visiblePosts(User $viewer): Builder
    {
        $posts = PhotoPost::query()->where('photo_posts.status', PhotoPostStatus::Ready);

        if (! $viewer->hasVerifiedEmail()) {
            return $posts->whereRaw('1 = 0');
        }

        return $posts->whereIn('photo_posts.user_id', $this->visibleAuthors($viewer)->select('users.id'));
    }

    /** @return Builder<User> */
    private function visibleAuthors(User $viewer): Builder
    {
        return User::query()
            ->whereNotNull('users.email_verified_at')
            ->whereExists(function (QueryBuilder $query) use ($viewer): void {
                $query->selectRaw('1')->from('users as photo_viewers')
                    ->where('photo_viewers.id', $viewer->getKey())
                    ->whereNotNull('photo_viewers.email_verified_at');
            })
            ->where(function (Builder $query) use ($viewer): void {
                $query->whereKey($viewer->getKey())
                    ->orWhereExists(function (QueryBuilder $query) use ($viewer): void {
                        $query->selectRaw('1')->from('follows')
                            ->whereColumn('follows.followed_id', 'users.id')
                            ->where('follows.follower_id', $viewer->getKey())
                            ->where('follows.status', 'approved');
                    });
            });
    }
}
