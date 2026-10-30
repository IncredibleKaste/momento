<?php

namespace App\Policies;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FollowPolicy
{
    public function approve(User $user, Follow $follow): Response
    {
        return $user->id === $follow->followed_id
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Follow $follow): Response
    {
        return in_array($user->id, [$follow->follower_id, $follow->followed_id], true)
            ? Response::allow() : Response::denyAsNotFound();
    }
}
