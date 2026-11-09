<?php

namespace App\Actions;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageConversation
{
    public function start(User $actor, User $member): Conversation
    {
        if ($actor->is($member)) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $member): Conversation {
            $members = $this->lockMembers($actor->id, $member->id);
            abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);
            abort_unless($this->mutuallyApproved($actor->id, $member->id), 404);

            [$first, $second] = $this->normalizedIds($actor->id, $member->id);
            $conversation = Conversation::query()->firstOrCreate([
                'member_one_id' => $first,
                'member_two_id' => $second,
            ]);

            foreach ([$first, $second] as $userId) {
                ConversationParticipant::query()->firstOrCreate([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                ]);
            }

            return $conversation;
        }, attempts: 3);
    }

    public function send(User $actor, int $conversationId, string $body, string $nonce): ConversationMessage
    {
        $conversation = Conversation::query()->findOrFail($conversationId);

        return DB::transaction(function () use ($actor, $conversation, $body, $nonce): ConversationMessage {
            $members = $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
            $current = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->participant($current, $actor);

            $existing = ConversationMessage::query()->where('conversation_id', $current->id)
                ->where('sender_id', $actor->id)->where('client_nonce', $nonce)->first();
            if ($existing) {
                if ($existing->body !== $body) {
                    throw ValidationException::withMessages(['body' => 'The retry key already belongs to a different message. Edit the draft and try again.']);
                }

                return $existing;
            }

            abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);
            if (! $this->mutuallyApproved($current->member_one_id, $current->member_two_id)) {
                throw ValidationException::withMessages(['body' => 'Messages can only be sent while both members follow each other.']);
            }

            return ConversationMessage::query()->create([
                'conversation_id' => $current->id,
                'sender_id' => $actor->id,
                'body' => $body,
                'client_nonce' => $nonce,
            ]);
        }, attempts: 3);
    }

    public function markRead(User $actor, int $conversationId, int $messageId): void
    {
        DB::transaction(function () use ($actor, $conversationId, $messageId): void {
            $conversation = Conversation::query()->findOrFail($conversationId);
            $participant = $this->participant($conversation, $actor);
            $message = ConversationMessage::query()->where('conversation_id', $conversation->id)
                ->whereKey($messageId)->where('sender_id', '!=', $actor->id)->firstOrFail();
            $participant = ConversationParticipant::query()->lockForUpdate()->findOrFail($participant->id);

            if ($participant->last_read_message_id === null || $message->id > $participant->last_read_message_id) {
                $participant->update(['last_read_message_id' => $message->id]);
            }
        }, attempts: 3);
    }

    public function participant(Conversation $conversation, User $actor): ConversationParticipant
    {
        return ConversationParticipant::query()->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)->firstOrFail();
    }

    private function mutuallyApproved(int $first, int $second): bool
    {
        return DB::table('follows')->where('status', 'approved')->where(function ($query) use ($first, $second): void {
            $query->where(fn ($query) => $query->where('follower_id', $first)->where('followed_id', $second))
                ->orWhere(fn ($query) => $query->where('follower_id', $second)->where('followed_id', $first));
        })->count() === 2;
    }

    /** @return Collection<int, User> */
    private function lockMembers(int $first, int $second): Collection
    {
        $members = User::query()->whereKey([$first, $second])->orderBy('id')->lockForUpdate()->get();
        abort_unless($members->count() === 2, 404);

        return $members;
    }

    /** @return array{int, int} */
    private function normalizedIds(int $first, int $second): array
    {
        return $first < $second ? [$first, $second] : [$second, $first];
    }
}
