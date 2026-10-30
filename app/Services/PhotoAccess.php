<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\User;

class PhotoAccess
{
    public function allows(User $viewer, User $author): bool
    {
        if (! $viewer->hasVerifiedEmail() || ! $author->hasVerifiedEmail()) {
            return false;
        }

        return $viewer->is($author) || Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('followed_id', $author->id)
            ->where('status', 'approved')
            ->exists();
    }
}
