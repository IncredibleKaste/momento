<?php

namespace App\Services;

use App\Models\ConversationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationAccess
{
    public function canSend(User $first, User $second): bool
    {
        if ($first->is($second) || ! $first->hasVerifiedEmail() || ! $second->hasVerifiedEmail()) {
            return false;
        }

        if ($this->mutuallyApproved($first->id, $second->id)) {
            return true;
        }

        return $this->latestRequest($first->id, $second->id)?->status === ConversationRequest::Accepted;
    }

    public function interactionMode(User $viewer, User $member): string
    {
        if ($viewer->is($member) || ! $viewer->hasVerifiedEmail() || ! $member->hasVerifiedEmail()) {
            return 'unavailable';
        }

        if ($this->canSend($viewer, $member)) {
            return 'direct';
        }

        $latest = $this->latestRequest($viewer->id, $member->id);
        $status = $latest?->status;
        if ($status === ConversationRequest::Pending && $latest->expires_at->isPast()) {
            $status = ConversationRequest::Expired;
        }

        if ($status === ConversationRequest::Pending) {
            return $latest->requester_id === $viewer->id ? 'outgoing' : 'incoming';
        }

        if ($status !== null && $latest?->reopened_at === null) {
            return $latest?->recipient_id === $viewer->id && $viewer->message_requests_enabled ? 'reopen' : 'closed';
        }

        return $member->message_requests_enabled ? 'request' : 'unavailable';
    }

    public function mayRequest(User $requester, User $recipient): bool
    {
        return $this->interactionMode($requester, $recipient) === 'request';
    }

    /** @return array{id: int, canStop: bool, mutual: bool}|null */
    public function requestPermission(User $viewer, User $member): ?array
    {
        $request = $this->latestRequest($viewer->id, $member->id);
        $mutual = $this->mutuallyApproved($viewer->id, $member->id);
        $active = $request && in_array($request->status, [ConversationRequest::Pending, ConversationRequest::Accepted], true)
            && ($request->status !== ConversationRequest::Pending || $request->expires_at->isFuture());

        if (! $active) {
            return null;
        }

        return [
            'id' => (int) $request->id,
            'canStop' => true,
            'mutual' => $mutual,
        ];
    }

    public function mutuallyApproved(int $first, int $second): bool
    {
        return DB::table('follows')->where('status', 'approved')->where(function ($query) use ($first, $second): void {
            $query->where(fn ($query) => $query->where('follower_id', $first)->where('followed_id', $second))
                ->orWhere(fn ($query) => $query->where('follower_id', $second)->where('followed_id', $first));
        })->count() === 2;
    }

    public function latestRequest(int $first, int $second): ?ConversationRequest
    {
        return ConversationRequest::query()->forPair($first, $second)->latest('id')->first();
    }
}
