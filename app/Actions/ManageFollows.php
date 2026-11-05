<?php

namespace App\Actions;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageFollows
{
    public function request(User $actor, User $member): Follow
    {
        if ($actor->is($member)) {
            throw ValidationException::withMessages(['follow' => 'You cannot follow yourself.']);
        }

        return DB::transaction(function () use ($actor, $member): Follow {
            $members = $this->lockMembers($actor->id, $member->id);
            abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);

            return Follow::query()->firstOrCreate([
                'follower_id' => $actor->id, 'followed_id' => $member->id,
            ], ['status' => 'pending']);
        }, attempts: 3);
    }

    public function approve(User $actor, Follow $follow): void
    {
        Gate::forUser($actor)->authorize('approve', $follow);

        DB::transaction(function () use ($actor, $follow): void {
            $members = $this->lockMembers($follow->follower_id, $follow->followed_id);
            abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);
            $current = Follow::query()->lockForUpdate()->findOrFail($follow->id);
            Gate::forUser($actor)->authorize('approve', $current);
            if ($current->status === 'pending') {
                $current->update(['status' => 'approved']);
            }
        }, attempts: 3);
    }

    public function remove(User $actor, Follow $follow): void
    {
        Gate::forUser($actor)->authorize('delete', $follow);

        DB::transaction(function () use ($actor, $follow): void {
            $members = $this->lockMembers($follow->follower_id, $follow->followed_id);
            abort_unless($members->firstWhere('id', $actor->id)?->hasVerifiedEmail(), 404);
            $current = Follow::query()->lockForUpdate()->findOrFail($follow->id);
            Gate::forUser($actor)->authorize('delete', $current);
            $current->delete();
        }, attempts: 3);
    }

    /** @return Collection<int, User> */
    private function lockMembers(int $first, int $second): Collection
    {
        // One order for both directions prevents reciprocal requests from deadlocking.
        $members = User::query()->whereKey([$first, $second])->orderBy('id')->lockForUpdate()->get();
        abort_unless($members->count() === 2, 404);

        return $members;
    }
}
