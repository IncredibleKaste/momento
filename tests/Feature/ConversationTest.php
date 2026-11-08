<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{User, User} */
function mutuallyFollowedPair(): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $alice->id, 'followed_id' => $bob->id]);
    Follow::factory()->approved()->create(['follower_id' => $bob->id, 'followed_id' => $alice->id]);

    return [$alice, $bob];
}

it('starts a thread only when both verified members approve both follow directions', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $alice->id, 'followed_id' => $bob->id]);
    $this->actingAs($alice)->post(route('messages.store', $bob))->assertNotFound();
    $this->assertDatabaseCount('conversations', 0);

    Follow::factory()->approved()->create(['follower_id' => $bob->id, 'followed_id' => $alice->id]);
    $this->post(route('messages.store', $bob))->assertRedirect();
    $conversation = Conversation::sole();
    expect($conversation->member_one_id)->toBe(min($alice->id, $bob->id));
    expect($conversation->member_two_id)->toBe(max($alice->id, $bob->id));
    $this->assertDatabaseCount('conversation_participants', 2);
});

it('reuses the same normalized thread when either participant starts it', function () {
    [$alice, $bob] = mutuallyFollowedPair();

    $this->actingAs($alice)->post(route('messages.store', $bob))->assertRedirect();
    $this->actingAs($bob)->post(route('messages.store', $alice))->assertRedirect();

    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseCount('conversation_participants', 2);
});

it('filters the existing rail by case-insensitive literal member name only', function () {
    $alice = User::factory()->create(['name' => 'Alicia 100%']);
    $bob = User::factory()->create(['name' => 'Alex']);
    $viewer = User::factory()->create();
    foreach ([$alice, $bob] as $member) {
        Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $member->id]);
        Follow::factory()->approved()->create(['follower_id' => $member->id, 'followed_id' => $viewer->id]);
        $this->actingAs($viewer)->post(route('messages.store', $member));
    }

    $this->actingAs($viewer)->get(route('messages.index', ['q' => '100%']))->assertInertia(fn (Assert $page) => $page
        ->has('conversations.data', 1)->where('conversations.data.0.member.name', 'Alicia 100%'));
    $this->get(route('messages.index', ['q' => 'aleX']))->assertInertia(fn (Assert $page) => $page
        ->has('conversations.data', 1)->where('conversations.data.0.member.name', 'Alex'));
});

it('encrypts bounded messages and retries the same nonce without duplicating delivery', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    $body = 'Hello <script>alert("x")</script>';
    $nonce = Str::uuid()->toString();
    $payload = ['body' => $body, 'nonce' => $nonce];

    $this->post(route('messages.send', $conversation), $payload)->assertRedirect();
    $this->post(route('messages.send', $conversation), $payload)->assertRedirect();
    $this->post(route('messages.send', $conversation), ['body' => 'Changed draft', 'nonce' => $nonce])
        ->assertSessionHasErrors(['body' => 'The retry key already belongs to a different message. Edit the draft and try again.']);

    $message = ConversationMessage::sole();
    expect($message->body)->toBe($body);
    expect(DB::table('conversation_messages')->value('body'))->not->toBe($body);
    $this->actingAs($bob)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.messages.0.body', $body)
        ->missing('thread.otherLastReadMessageId')
        ->missing('thread.counterpartyLastReadMessageId'));
    $this->assertDatabaseCount('conversation_messages', 1);
    $this->post(route('messages.send', $conversation), ['body' => str_repeat('a', 4001), 'nonce' => Str::uuid()->toString()])
        ->assertSessionHasErrors('body');
    $this->assertDatabaseCount('conversation_messages', 1);
});

it('keeps existing history readable after unfollow while refusing new messages', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    $this->post(route('messages.send', $conversation), ['body' => 'Saved history', 'nonce' => Str::uuid()->toString()]);
    Follow::query()->where('follower_id', $alice->id)->where('followed_id', $bob->id)->delete();

    $this->actingAs($bob)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.messages.0.body', 'Saved history')->where('thread.canSend', false));
    $this->post(route('messages.send', $conversation), ['body' => 'Blocked after revocation', 'nonce' => Str::uuid()->toString()])
        ->assertSessionHasErrors(['body' => 'Messages can only be sent while both members follow each other.']);
    $this->assertDatabaseCount('conversation_messages', 1);
});

it('keeps history readable when the other member loses verification but disables sending', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    $message = ConversationMessage::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $bob->id,
        'body' => 'Retained after verification change',
        'client_nonce' => Str::uuid()->toString(),
    ]);
    $bob->forceFill(['email_verified_at' => null])->save();

    $this->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.messages.0.body', $message->body)->where('thread.canSend', false));
});

it('hides conversation access from guests unverified members and nonparticipants', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $outsider = User::factory()->create();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    $message = ConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'Private', 'client_nonce' => Str::uuid()->toString()]);
    $foreignMember = User::factory()->create();
    $foreignConversation = Conversation::query()->create([
        'member_one_id' => min($bob->id, $foreignMember->id),
        'member_two_id' => max($bob->id, $foreignMember->id),
    ]);
    $foreignConversation->participants()->createMany([['user_id' => $bob->id], ['user_id' => $foreignMember->id]]);
    $foreignMessage = ConversationMessage::create([
        'conversation_id' => $foreignConversation->id,
        'sender_id' => $foreignMember->id,
        'body' => 'Other thread',
        'client_nonce' => Str::uuid()->toString(),
    ]);

    auth()->logout();
    $this->get(route('messages.show', $conversation))->assertRedirectToRoute('login');
    $this->post(route('messages.send', $conversation), ['body' => 'Guest', 'nonce' => Str::uuid()->toString()])->assertRedirectToRoute('login');
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get(route('messages.show', $conversation))->assertRedirectToRoute('verification.notice');
    $this->post(route('messages.send', $conversation), ['body' => 'Unverified', 'nonce' => Str::uuid()->toString()])->assertRedirectToRoute('verification.notice');
    $this->actingAs($outsider)->get(route('messages.show', $conversation))->assertNotFound();
    $this->post(route('messages.send', $conversation), ['body' => 'forged', 'nonce' => Str::uuid()->toString()])->assertNotFound();
    $this->post(route('messages.read', [$conversation, $message]))->assertNotFound();
    $this->actingAs($bob)->post(route('messages.read', [$conversation, $foreignMessage]))->assertNotFound();
    $this->assertDatabaseCount('conversation_messages', 2);
});

it('returns the same not found response for malformed nonparticipant sends and missing threads', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $outsider = User::factory()->create();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();

    $missingThread = $this->post(route('messages.send', PHP_INT_MAX), ['body' => 'valid', 'nonce' => Str::uuid()->toString()]);
    $malformedNonparticipant = $this->actingAs($outsider)->post(route('messages.send', $conversation), ['body' => '', 'nonce' => 'invalid']);
    $validNonparticipant = $this->post(route('messages.send', $conversation), ['body' => 'valid', 'nonce' => Str::uuid()->toString()]);

    expect($malformedNonparticipant->getStatusCode())->toBe($missingThread->getStatusCode())
        ->and($validNonparticipant->getStatusCode())->toBe($missingThread->getStatusCode())
        ->and($missingThread->getStatusCode())->toBe(404);
    $this->assertDatabaseCount('conversation_messages', 0);
});

it('accepts only inbound messages as read and never moves a participant watermark backward', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    $first = ConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'First', 'client_nonce' => Str::uuid()->toString()]);
    $second = ConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'Second', 'client_nonce' => Str::uuid()->toString()]);
    $ownMessage = ConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $bob->id, 'body' => 'Reply', 'client_nonce' => Str::uuid()->toString()]);

    $this->actingAs($bob)->get(route('messages.show', ['conversation' => $conversation->id, 'before' => $ownMessage->id]))->assertOk();
    $this->post(route('messages.read', [$conversation, $second]))->assertNoContent()->assertHeaderMissing('Location');
    $this->post(route('messages.read', [$conversation, $first]))->assertNoContent();
    $this->post(route('messages.read', [$conversation, $ownMessage]))->assertNotFound();
    expect(ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $bob->id)->value('last_read_message_id'))->toBe($second->id);
    $this->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page->where('thread.unreadCount', 0)->where('thread.lastReadMessageId', $second->id));
    $this->actingAs($alice)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page->where('thread.unreadCount', 1)->where('thread.lastReadMessageId', null));
});

it('paginates message history in stable descending cursors with a bounded page', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    foreach (range(1, 51) as $number) {
        ConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => "Message {$number}", 'client_nonce' => Str::uuid()->toString()]);
    }

    $this->actingAs($bob)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->has('thread.messages', 50)->where('thread.messages.0.body', 'Message 2')
        ->where('thread.messages.49.body', 'Message 51')->where('thread.nextBefore', 2)
        ->where('thread.isLatestPage', true));
    $this->get(route('messages.show', $conversation).'?before=2')->assertInertia(fn (Assert $page) => $page
        ->has('thread.messages', 1)->where('thread.messages.0.body', 'Message 1')->where('thread.nextBefore', null)
        ->where('thread.isLatestPage', false));
});

it('returns rate limit failures to the same thread and retains the idempotent message', function () {
    [$alice, $bob] = mutuallyFollowedPair();
    $this->actingAs($alice)->post(route('messages.store', $bob));
    $conversation = Conversation::sole();
    $payload = ['body' => 'Keep this draft', 'nonce' => Str::uuid()->toString()];
    $this->withHeader('X-Inertia', 'true');
    foreach (range(1, 60) as $attempt) {
        $this->post(route('messages.send', $conversation), $payload)->assertRedirect();
    }

    $this->post(route('messages.send', $conversation), $payload)->assertRedirectToRoute('messages.show', $conversation)
        ->assertSessionHasErrors(['body' => 'Too many messages. Wait a minute, then retry your draft.']);
    $this->assertDatabaseCount('conversation_messages', 1);
});
