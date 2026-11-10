<?php

use App\Actions\ManageConversation;
use App\Actions\ManageConversationRequests;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRequest;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('sends a private encrypted request once and rejects a changed retry', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $otherRecipient = User::factory()->create(['message_requests_enabled' => true]);
    $body = 'Hello <script>canary</script>';
    $nonce = Str::uuid()->toString();
    $payload = ['body' => $body, 'nonce' => $nonce];

    $this->actingAs($requester)->post(route('message-requests.store', $recipient), $payload)->assertRedirect();
    $this->post(route('message-requests.store', $recipient), $payload)->assertRedirect();
    $this->post(route('message-requests.store', $recipient), ['body' => 'Changed text', 'nonce' => $nonce])
        ->assertSessionHasErrors(['body' => 'The retry key already belongs to a different request. Edit the draft and try again.']);
    $this->post(route('message-requests.store', $otherRecipient), ['body' => $body, 'nonce' => $nonce])
        ->assertSessionHasErrors(['body' => 'The retry key already belongs to a different request. Edit the draft and try again.']);

    $request = ConversationRequest::sole();
    expect($request->body)->toBe($body);
    expect(DB::table('conversation_requests')->value('body'))->not->toBe($body);
    expect($request->status)->toBe(ConversationRequest::Pending);
    $this->assertDatabaseCount('conversation_requests', 1);
    $this->actingAs($recipient)->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->where('requests.incomingCount', 1)
        ->where('requests.incoming.data.0.body', $body)
        ->where('requests.incoming.data.0.canAccept', true)
        ->missing('requests.incoming.data.0.member.email'));
    $this->get(route('messages.index'))
        ->assertDontSee('<script>canary</script>', false);
});

it('accepts a request into a readable encrypted conversation without mutual follows', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $body = 'First private message';
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => $body,
        'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();

    $this->actingAs($recipient)->post(route('message-requests.accept', $request))->assertRedirect();

    $conversation = Conversation::sole();
    expect(app(ManageConversation::class)->start($requester, $recipient)->id)->toBe($conversation->id);
    $message = ConversationMessage::sole();
    expect($message->body)->toBe($body);
    expect(DB::table('conversation_messages')->value('body'))->not->toBe($body);
    expect($request->fresh()->status)->toBe(ConversationRequest::Accepted);
    $this->assertDatabaseCount('conversation_participants', 2);
    $this->actingAs($requester)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.canSend', true)
        ->where('thread.messages.0.body', $body)
        ->where('thread.requestPermission.mutual', false));
    $this->getJson(route('messages.recipients', ['q' => $recipient->name]))
        ->assertOk()
        ->assertJsonPath('data.0.requestState', 'direct')
        ->assertJsonPath('data.0.canMessage', true);
});

it('includes viewer-scoped request panels on an accepted conversation thread', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Accepted conversation',
        'nonce' => Str::uuid()->toString(),
    ]);
    $acceptedRequest = ConversationRequest::sole();
    $this->actingAs($recipient)->post(route('message-requests.accept', $acceptedRequest));
    $conversation = Conversation::sole();

    $pendingSender = User::factory()->create(['name' => 'Visible pending sender']);
    app(ManageConversationRequests::class)->initiate($pendingSender, $recipient, 'Visible pending request', Str::uuid()->toString());
    $unrelatedSender = User::factory()->create(['name' => 'Unrelated sender']);
    $unrelatedRecipient = User::factory()->create(['message_requests_enabled' => true]);
    app(ManageConversationRequests::class)->initiate($unrelatedSender, $unrelatedRecipient, 'Unrelated private request', Str::uuid()->toString());

    $this->actingAs($recipient)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('selectedRecipient', null)
        ->where('requests.incomingCount', 1)
        ->where('requests.outgoingCount', 0)
        ->has('requests.incoming.data', 2)
        ->where('requests.incoming.data.0.member.name', 'Visible pending sender')
        ->where('requests.incoming.data.1.member.id', $requester->id)
        ->missing('requests.incoming.data.2')
        ->missing('requests.outgoing.data.0')
        ->missing('requests.incoming.data.0.member.email')
        ->where('thread.id', $conversation->id));

});

it('lets only the original recipient reopen after decline or expiry', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $other = User::factory()->create(['message_requests_enabled' => true]);
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'First request', 'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();

    $this->actingAs($recipient)->post(route('message-requests.decline', $request))->assertRedirect();
    $this->actingAs($other)->post(route('message-requests.reopen', $request))->assertNotFound();
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Retry without reopening', 'nonce' => Str::uuid()->toString(),
    ])->assertSessionHasErrors('body');
    $recipient->forceFill(['message_requests_enabled' => false])->save();
    $this->actingAs($recipient)->post(route('message-requests.reopen', $request))->assertNotFound();
    $recipient->forceFill(['message_requests_enabled' => true])->save();
    $this->actingAs($recipient)->post(route('message-requests.reopen', $request))->assertRedirect();
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Reopened request', 'nonce' => Str::uuid()->toString(),
    ])->assertRedirect();
    expect(ConversationRequest::count())->toBe(2);
    $this->actingAs($recipient)->post(route('message-requests.accept', $request))->assertNotFound();

    $latest = ConversationRequest::query()->latest('id')->firstOrFail();
    $this->travel(31)->days();
    $this->actingAs($recipient)->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->where('requests.incoming.data.0.status', ConversationRequest::Expired)
        ->where('requests.incoming.data.0.canReopen', true));
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Too soon', 'nonce' => Str::uuid()->toString(),
    ])->assertSessionHasErrors('body');
    $this->actingAs($recipient)->post(route('message-requests.reopen', $latest))->assertRedirect();
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'After expiry reopen', 'nonce' => Str::uuid()->toString(),
    ])->assertRedirect();
    expect(ConversationRequest::count())->toBe(3);
});

it('revokes request messaging atomically on unfollow while retaining conversation history', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $follow = Follow::factory()->approved()->create(['follower_id' => $requester->id, 'followed_id' => $recipient->id]);
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Request body', 'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();
    $this->actingAs($recipient)->post(route('message-requests.accept', $request));
    $conversation = Conversation::sole();

    $this->actingAs($requester)->delete(route('follows.destroy', $follow))->assertRedirect();

    expect($request->fresh()->status)->toBe(ConversationRequest::Stopped);
    $this->actingAs($requester)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.canSend', false)
        ->where('thread.messages.0.body', 'Request body'));
    $this->post(route('messages.send', $conversation), ['body' => 'Denied', 'nonce' => Str::uuid()->toString()])
        ->assertSessionHasErrors('body');
    $this->assertDatabaseCount('conversation_messages', 1);
});

it('requires the original recipient to reopen a stopped request without erasing its prior attempt', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'First request', 'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();
    $this->actingAs($recipient)->post(route('message-requests.accept', $request));
    $this->actingAs($requester)->post(route('message-requests.stop', $request))->assertRedirect();
    expect($request->fresh()->status)->toBe(ConversationRequest::Stopped);

    $this->post(route('message-requests.store', $recipient), [
        'body' => 'Blocked until reopen', 'nonce' => Str::uuid()->toString(),
    ])->assertSessionHasErrors('body');
    $this->actingAs($recipient)->post(route('message-requests.reopen', $request))->assertRedirect();
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'New request after reopen', 'nonce' => Str::uuid()->toString(),
    ])->assertRedirect();

    expect(ConversationRequest::count())->toBe(2);
    expect(ConversationRequest::query()->oldest('id')->firstOrFail()->status)->toBe(ConversationRequest::Stopped);
    expect(ConversationRequest::query()->latest('id')->firstOrFail()->status)->toBe(ConversationRequest::Pending);
});

it('allows decline and stop after the other member loses verification without granting new sends', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Unverified sender request', 'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();
    $requester->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($recipient)->post(route('message-requests.accept', $request))->assertNotFound();
    $this->assertDatabaseHas('conversation_requests', ['id' => $request->id, 'status' => ConversationRequest::Pending]);
    $this->post(route('message-requests.decline', $request))->assertRedirect();
    expect($request->fresh()->status)->toBe(ConversationRequest::Declined);

    $secondSender = User::factory()->create();
    $secondRecipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->actingAs($secondSender)->post(route('message-requests.store', $secondRecipient), [
        'body' => 'Accepted before verification changed', 'nonce' => Str::uuid()->toString(),
    ]);
    $acceptedRequest = ConversationRequest::query()->latest('id')->firstOrFail();
    $this->actingAs($secondRecipient)->post(route('message-requests.accept', $acceptedRequest))->assertRedirect();
    $conversation = Conversation::query()->latest('id')->firstOrFail();
    $secondSender->forceFill(['email_verified_at' => null])->save();
    $this->post(route('message-requests.stop', $acceptedRequest))->assertRedirect();
    expect($acceptedRequest->fresh()->status)->toBe(ConversationRequest::Stopped);
    $this->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.canSend', false)
        ->where('thread.messages.0.body', 'Accepted before verification changed'));
});

it('requires recipient opt-in and returns only bounded verified display names', function () {
    $viewer = User::factory()->create();
    $members = User::factory()->count(7)->sequence(
        ['name' => 'Lookup A'], ['name' => 'Lookup B'], ['name' => 'Lookup C'], ['name' => 'Lookup D'],
        ['name' => 'Lookup E'], ['name' => 'Lookup F'], ['name' => 'Lookup G'],
    )->create(['message_requests_enabled' => true]);
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $members[5]->id]);
    Follow::factory()->approved()->create(['follower_id' => $members[5]->id, 'followed_id' => $viewer->id]);
    $optedOut = User::factory()->create(['name' => 'Lookup Hidden', 'message_requests_enabled' => false]);

    $response = $this->actingAs($viewer)->getJson(route('messages.recipients', ['q' => 'Lookup ']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(5);
    expect($response->json('data.0.name'))->toBe('Lookup F');
    expect($response->json('data.0.requestState'))->toBe('direct');
    expect($response->json('data.0.canMessage'))->toBeTrue();
    expect($response->json('data.1.requestState'))->toBe('request');
    expect($response->json('data.1.canMessage'))->toBeFalse();
    expect($response->json('data.0'))->not->toHaveKey('email');
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($optedOut->id);
    $this->actingAs($viewer)->getJson(route('messages.recipients', ['q' => 'Lookup Hidden']))->assertJsonPath('data', []);
});

it('looks past ineligible name matches before applying the five-result bound', function () {
    User::factory()->count(25)->sequence(fn ($sequence) => [
        'name' => sprintf('Needle %02d', $sequence->index),
        'message_requests_enabled' => false,
    ])->create();
    $eligible = User::factory()->create([
        'name' => 'Needle 99',
        'message_requests_enabled' => true,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('messages.recipients', ['q' => 'Needle']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $eligible->id)
        ->assertJsonCount(1, 'data');
});

it('shows a closed terminal request for its original recipient even when requests are now disabled', function () {
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $sender = User::factory()->create(['name' => 'Closed sender', 'message_requests_enabled' => false]);
    $this->actingAs($sender)->post(route('message-requests.store', $recipient), [
        'body' => 'A request to decline',
        'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();
    $this->actingAs($recipient)->post(route('message-requests.decline', $request));
    $recipient->forceFill(['message_requests_enabled' => false])->save();

    $this->actingAs($recipient)->getJson(route('messages.recipients', ['q' => 'Closed sender']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $sender->id)
        ->assertJsonPath('data.0.requestState', 'closed')
        ->assertJsonPath('data.0.canReopen', false);
});

it('rechecks recipient opt-in on the locked database row before creating a request', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $staleRecipient = User::query()->findOrFail($recipient->id);
    DB::table('users')->where('id', $recipient->id)->update(['message_requests_enabled' => false]);

    expect(fn () => app(ManageConversationRequests::class)->initiate(
        $requester,
        $staleRecipient,
        'This must not be admitted',
        Str::uuid()->toString(),
    ))->toThrow(HttpException::class);
    $this->assertDatabaseCount('conversation_requests', 0);
});

it('enforces five successful requests per UTC day and five active pending requests', function () {
    $this->freezeTime();
    $requester = User::factory()->create();
    $this->actingAs($requester);
    foreach (range(1, 5) as $number) {
        $recipient = User::factory()->create(['message_requests_enabled' => true]);
        $this->post(route('message-requests.store', $recipient), [
            'body' => "Request {$number}", 'nonce' => Str::uuid()->toString(),
        ])->assertRedirect();
        $this->actingAs($recipient)->post(route('message-requests.decline', ConversationRequest::latest('id')->firstOrFail()))->assertRedirect();
        $this->actingAs($requester);
    }
    $sixthRecipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->post(route('message-requests.store', $sixthRecipient), [
        'body' => 'Sixth today', 'nonce' => Str::uuid()->toString(),
    ])->assertSessionHasErrors(['body' => 'You have reached today’s request limit.']);
    expect(ConversationRequest::count())->toBe(5);

    $this->travel(1)->day();
    foreach (range(1, 5) as $number) {
        $recipient = User::factory()->create(['message_requests_enabled' => true]);
        $this->post(route('message-requests.store', $recipient), [
            'body' => "Pending {$number}", 'nonce' => Str::uuid()->toString(),
        ])->assertRedirect();
        $this->travel(1)->day();
    }
    $pendingRecipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->post(route('message-requests.store', $pendingRecipient), [
        'body' => 'Sixth pending', 'nonce' => Str::uuid()->toString(),
    ])->assertSessionHasErrors(['body' => 'You already have five pending message requests.']);
    expect(ConversationRequest::count())->toBe(10);
});

it('paginates incoming requests independently from sent requests and chats', function () {
    $viewer = User::factory()->create(['message_requests_enabled' => true]);
    $requests = app(ManageConversationRequests::class);
    foreach (range(1, 26) as $number) {
        $sender = User::factory()->create();
        $requests->initiate($sender, $viewer, "Incoming {$number}", Str::uuid()->toString());
    }
    $outgoing = User::factory()->create(['message_requests_enabled' => true]);
    $requests->initiate($viewer, $outgoing, 'One outgoing request', Str::uuid()->toString());
    $chatMember = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $viewer->id, 'followed_id' => $chatMember->id]);
    Follow::factory()->approved()->create(['follower_id' => $chatMember->id, 'followed_id' => $viewer->id]);
    app(ManageConversation::class)->start($viewer, $chatMember);

    $this->actingAs($viewer)->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->has('requests.incoming.data', 25)
        ->where('requests.incoming.current_page', 1)
        ->has('requests.outgoing.data', 1)
        ->where('requests.outgoing.current_page', 1)
        ->has('conversations.data', 1));
    $this->get(route('messages.index', ['incoming_requests_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('requests.incoming.data', 1)
        ->where('requests.incoming.current_page', 2)
        ->has('requests.outgoing.data', 1)
        ->where('requests.outgoing.current_page', 1)
        ->has('conversations.data', 1));
});

it('stores the request preference as owner-only and off by default', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    expect($owner->message_requests_enabled)->toBeFalse();
    $this->actingAs($owner)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('messageRequestsEnabled', false));
    $this->patch(route('profile.update'), ['name' => $owner->name, 'message_requests_enabled' => true, 'user_id' => $other->id])
        ->assertRedirectToRoute('profile.edit')->assertSessionHasNoErrors();

    expect($owner->fresh()->message_requests_enabled)->toBeTrue();
    expect($other->fresh()->message_requests_enabled)->toBeFalse();
});

it('validates request bodies before saving and hides requests from other recipients', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $other = User::factory()->create();
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => str_repeat('x', 501), 'nonce' => Str::uuid()->toString(),
    ])->assertSessionHasErrors('body');
    $this->post(route('message-requests.store', $recipient), ['body' => "bad\0text", 'nonce' => Str::uuid()->toString()])->assertSessionHasErrors('body');
    $this->post(route('message-requests.store', $recipient), ['body' => 'Valid', 'nonce' => Str::uuid()->toString()]);
    $request = ConversationRequest::sole();

    $this->actingAs($other)->post(route('message-requests.accept', $request))->assertNotFound();
    $this->actingAs($recipient)->post(route('message-requests.accept', $request))->assertRedirect();
    $this->assertDatabaseCount('conversations', 1);
});

it('routes request-action rate limits back to messages without false state changes', function () {
    $requester = User::factory()->create();
    $recipient = User::factory()->create(['message_requests_enabled' => true]);
    $this->actingAs($requester)->post(route('message-requests.store', $recipient), [
        'body' => 'Request', 'nonce' => Str::uuid()->toString(),
    ]);
    $request = ConversationRequest::sole();
    RateLimiter::clear('message-request-actions:'.$recipient->id);
    $this->withHeader('X-Inertia', 'true');

    foreach (range(1, 60) as $attempt) {
        $this->actingAs($recipient)->post(route('message-requests.stop', $request))->assertRedirect();
    }
    $response = $this->post(route('message-requests.stop', $request));

    $response->assertRedirectToRoute('messages.index')
        ->assertSessionHasErrors(['request' => 'Too many request changes. Wait a minute, then try again.']);
    expect($request->fresh()->status)->toBe(ConversationRequest::Stopped);
    $this->assertDatabaseCount('conversation_requests', 1);
});
