<?php

namespace App\Actions;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRequest;
use App\Models\User;
use App\Services\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageConversationRequests
{
    public function __construct(
        private ConversationAccess $access,
        private ManageConversation $conversations,
    ) {}

    public function initiate(User $actor, User $recipient, string $body, string $nonce): Conversation|ConversationRequest
    {
        if ($actor->is($recipient)) {
            abort(404);
        }

        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Write a message before sending.']);
        }

        return DB::transaction(function () use ($actor, $recipient, $body, $nonce): Conversation|ConversationRequest {
            $actorId = $actor->id;
            $recipientId = $recipient->id;
            $members = $this->lockMembers($actorId, $recipientId);
            abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);
            $lockedActor = $members->firstWhere('id', $actorId);
            $lockedRecipient = $members->firstWhere('id', $recipientId);
            abort_unless($lockedActor instanceof User && $lockedRecipient instanceof User, 404);
            $actor = $lockedActor;
            $recipient = $lockedRecipient;

            $existing = ConversationRequest::query()->where('requester_id', $actor->id)
                ->where('client_nonce', $nonce)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->recipient_id !== $recipient->id || $existing->body !== $body) {
                    throw ValidationException::withMessages(['body' => 'The retry key already belongs to a different request. Edit the draft and try again.']);
                }

                return $existing;
            }

            if ($this->access->mutuallyApproved($actor->id, $recipient->id)) {
                return $this->startChatWithFirstMessage($actor, $recipient, $body, $nonce);
            }

            abort_unless($recipient->message_requests_enabled, 404);

            $latest = $this->access->latestRequest($actor->id, $recipient->id);
            $status = $this->effectiveStatus($latest);

            if ($status === ConversationRequest::Accepted) {
                return $this->startChatWithFirstMessage($actor, $recipient, $body, $nonce);
            }

            if ($status === ConversationRequest::Pending) {
                throw ValidationException::withMessages(['body' => 'A request is already waiting for this member.']);
            }

            if ($latest && $latest->reopened_at === null) {
                throw ValidationException::withMessages(['body' => 'This member must reopen requests before you can send another.']);
            }

            $now = Carbon::now('UTC');
            $dayStart = $now->copy()->startOfDay();
            if (ConversationRequest::query()->where('requester_id', $actor->id)
                ->where('created_at', '>=', $dayStart)->where('created_at', '<', $dayStart->copy()->addDay())->count() >= 5) {
                throw ValidationException::withMessages(['body' => 'You have reached today’s request limit.']);
            }

            if ($this->activePendingCount($actor->id, $now) >= 5) {
                throw ValidationException::withMessages(['body' => 'You already have five pending message requests.']);
            }

            $ids = [$actor->id, $recipient->id];
            sort($ids);

            return ConversationRequest::query()->create([
                'requester_id' => $actor->id,
                'recipient_id' => $recipient->id,
                'member_one_id' => $ids[0],
                'member_two_id' => $ids[1],
                'body' => $body,
                'client_nonce' => $nonce,
                'status' => ConversationRequest::Pending,
                'expires_at' => $now->copy()->addDays(30),
            ]);
        }, attempts: 3);
    }

    public function respond(User $actor, int $requestId, bool $accept): ConversationRequest
    {
        return DB::transaction(function () use ($actor, $requestId, $accept): ConversationRequest {
            $request = ConversationRequest::query()->findOrFail($requestId);
            $members = $this->lockMembers($request->requester_id, $request->recipient_id);
            $current = ConversationRequest::query()->lockForUpdate()->findOrFail($requestId);
            abort_unless($current->recipient_id === $actor->id, 404);
            abort_unless($this->access->latestRequest($current->requester_id, $current->recipient_id)?->is($current), 404);

            $expected = $accept ? ConversationRequest::Accepted : ConversationRequest::Declined;
            if ($current->status === $expected) {
                return $current;
            }

            if ($this->effectiveStatus($current) !== ConversationRequest::Pending) {
                throw ValidationException::withMessages(['request' => 'This request is no longer active.']);
            }

            if ($accept) {
                abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);
            }

            $current->update([
                'status' => $expected,
                'responded_at' => Carbon::now('UTC'),
            ]);

            if ($accept) {
                $conversation = $this->conversations->createPair($current->requester_id, $current->recipient_id);
                ConversationMessage::query()->firstOrCreate([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $current->requester_id,
                    'client_nonce' => Str::uuid()->toString(),
                ], ['body' => $current->body]);
            }

            return $current->fresh();
        }, attempts: 3);
    }

    public function stop(User $actor, int $requestId): ConversationRequest
    {
        return DB::transaction(function () use ($actor, $requestId): ConversationRequest {
            $request = ConversationRequest::query()->findOrFail($requestId);
            $this->lockMembers($request->requester_id, $request->recipient_id);
            $current = ConversationRequest::query()->lockForUpdate()->findOrFail($requestId);
            abort_unless($actor->id === $current->requester_id || $actor->id === $current->recipient_id, 404);
            abort_unless($this->access->latestRequest($current->requester_id, $current->recipient_id)?->is($current), 404);

            $status = $this->effectiveStatus($current);
            if (in_array($status, [ConversationRequest::Pending, ConversationRequest::Accepted], true)) {
                $current->update([
                    'status' => ConversationRequest::Stopped,
                    'stopped_at' => Carbon::now('UTC'),
                ]);
            }

            return $current->fresh();
        }, attempts: 3);
    }

    public function reopen(User $actor, int $requestId): ConversationRequest
    {
        return DB::transaction(function () use ($actor, $requestId): ConversationRequest {
            $request = ConversationRequest::query()->findOrFail($requestId);
            $members = $this->lockMembers($request->requester_id, $request->recipient_id);
            $current = ConversationRequest::query()->lockForUpdate()->findOrFail($requestId);
            abort_unless($current->recipient_id === $actor->id, 404);
            $recipient = $members->firstWhere('id', $actor->id);
            abort_unless($recipient instanceof User && $recipient->message_requests_enabled, 404);

            if ($current->status === ConversationRequest::Pending && $current->expires_at->isPast()) {
                $current->update(['status' => ConversationRequest::Expired]);
            }

            abort_unless(in_array($current->status, [ConversationRequest::Declined, ConversationRequest::Expired, ConversationRequest::Stopped], true), 404);
            abort_unless($this->access->latestRequest($current->requester_id, $current->recipient_id)?->is($current), 404);

            $current->update(['reopened_at' => Carbon::now('UTC')]);

            return $current->fresh();
        }, attempts: 3);
    }

    public function revokeAfterUnfollow(int $firstMemberId, int $secondMemberId): void
    {
        $latest = $this->access->latestRequest($firstMemberId, $secondMemberId);
        if (! $latest) {
            return;
        }

        if (in_array($this->effectiveStatus($latest), [ConversationRequest::Pending, ConversationRequest::Accepted], true)) {
            $latest->update([
                'status' => ConversationRequest::Stopped,
                'stopped_at' => Carbon::now('UTC'),
            ]);
        }
    }

    /** @return LengthAwarePaginator<int, ConversationRequest> */
    public function pageFor(User $member, bool $incoming): LengthAwarePaginator
    {
        $pageName = $incoming ? 'incoming_requests_page' : 'outgoing_requests_page';

        return $this->latestRequests()
            ->where($incoming ? 'recipient_id' : 'requester_id', $member->id)
            ->with(['requester:id,name', 'recipient:id,name'])
            ->latest('id')->paginate(25, ['*'], $pageName)->withQueryString();
    }

    /** @return array{incoming: int, outgoing: int} */
    public function activePendingCounts(User $member): array
    {
        $base = $this->latestRequests()->where('status', ConversationRequest::Pending)
            ->where('expires_at', '>', Carbon::now('UTC'));

        return [
            'incoming' => (clone $base)->where('recipient_id', $member->id)->count(),
            'outgoing' => (clone $base)->where('requester_id', $member->id)->count(),
        ];
    }

    /** @return Builder<ConversationRequest> */
    public function latestRequests(): Builder
    {
        return ConversationRequest::query()->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('conversation_requests as newer')
                ->whereColumn('newer.member_one_id', 'conversation_requests.member_one_id')
                ->whereColumn('newer.member_two_id', 'conversation_requests.member_two_id')
                ->whereColumn('newer.id', '>', 'conversation_requests.id');
        });
    }

    private function startChatWithFirstMessage(User $actor, User $recipient, string $body, string $nonce): Conversation
    {
        $conversation = $this->conversations->createPair($actor->id, $recipient->id);
        $this->conversations->send($actor, $conversation->id, $body, $nonce);

        return $conversation;
    }

    public function effectiveStatus(?ConversationRequest $request): ?string
    {
        if ($request?->status === ConversationRequest::Pending && $request->expires_at->isPast()) {
            return ConversationRequest::Expired;
        }

        return $request?->status;
    }

    private function activePendingCount(int $requesterId, Carbon $now): int
    {
        return $this->latestRequests()->where('requester_id', $requesterId)
            ->where('status', ConversationRequest::Pending)->where('expires_at', '>', $now)->count();
    }

    /** @return Collection<int, User> */
    private function lockMembers(int $first, int $second): Collection
    {
        $members = User::query()->whereKey([$first, $second])->orderBy('id')->lockForUpdate()->get();
        abort_unless($members->count() === 2, 404);

        return $members;
    }
}
