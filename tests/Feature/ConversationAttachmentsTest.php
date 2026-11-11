<?php

use App\Actions\ManageConversation;
use App\Actions\ManageConversationAttachments;
use App\Enums\PhotoPostFailure;
use App\Exceptions\PhotoDecoderException;
use App\Jobs\ProcessConversationAttachments;
use App\Models\Conversation;
use App\Models\ConversationAttachmentSend;
use App\Models\ConversationMessage;
use App\Models\Follow;
use App\Models\User;
use App\Services\PhotoDecoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{User, User, Conversation} */
function conversationAttachmentPair(): array
{
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $sender->id, 'followed_id' => $recipient->id]);
    Follow::factory()->approved()->create(['follower_id' => $recipient->id, 'followed_id' => $sender->id]);
    $conversation = app(ManageConversation::class)->createPair($sender->id, $recipient->id);

    return [$sender, $recipient, $conversation];
}

function conversationAttachmentImage(): UploadedFile
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//2Q==');

    return UploadedFile::fake()->createWithContent('source.jpg', $jpeg);
}

it('stores one private attachment attempt idempotently and reserves its nonce against text sends', function () {
    Storage::fake('conversation_attachments');
    Queue::fake();
    [$sender, , $conversation] = conversationAttachmentPair();
    $nonce = Str::uuid()->toString();
    $payload = ['body' => 'private caption', 'nonce' => $nonce, 'attachments' => [conversationAttachmentImage()]];

    $first = $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), $payload, ['Accept' => 'application/json']);
    $first->assertAccepted()->assertJsonPath('nonce', $nonce)->assertJsonPath('status', 'processing');
    $first->assertJsonMissingPath('files')->assertJsonMissingPath('path');
    $send = ConversationAttachmentSend::sole();
    expect(DB::table('conversation_attachment_sends')->value('body'))->not->toBe('private caption')
        ->and($send->status)->toBe('queued')
        ->and($send->reserved_bytes)->toBeGreaterThan(0)
        ->and($send->files[0]['inputKey'])->toBe($send->attempt_token.'/0-input');
    Storage::disk('conversation_attachments')->assertExists($send->files[0]['inputKey']);
    Queue::assertPushed(ProcessConversationAttachments::class, fn (ProcessConversationAttachments $job): bool => $job->sendId === $send->id
        && $job->attemptToken === $send->attempt_token);

    $retry = $this->post(route('messages.attachments.store', $conversation), [
        'body' => 'private caption', 'nonce' => $nonce, 'attachments' => [conversationAttachmentImage()],
    ], ['Accept' => 'application/json']);
    $retry->assertAccepted()->assertJsonPath('status', 'processing');
    expect(ConversationAttachmentSend::count())->toBe(1);

    $conflict = $this->post(route('messages.attachments.store', $conversation), [
        'body' => 'different caption', 'nonce' => $nonce, 'attachments' => [conversationAttachmentImage()],
    ], ['Accept' => 'application/json']);
    $conflict->assertConflict();

    $this->post(route('messages.send', $conversation), ['body' => 'split text send', 'nonce' => $nonce])
        ->assertSessionHasErrors(['body' => 'This retry key belongs to an attachment message. Start a new message with a new key.']);
    $this->assertDatabaseCount('conversation_messages', 0);
});

it('rejects oversized or excessive files before reserving a send', function () {
    Storage::fake('conversation_attachments');
    [$sender, , $conversation] = conversationAttachmentPair();
    $payload = ['nonce' => Str::uuid()->toString(), 'attachments' => [
        conversationAttachmentImage(), conversationAttachmentImage(), conversationAttachmentImage(),
    ]];

    $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), $payload, ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->assertDatabaseCount('conversation_attachment_sends', 0);

    $oversized = UploadedFile::fake()->create('large.jpg', 19_532, 'image/jpeg');
    $this->post(route('messages.attachments.store', $conversation), [
        'nonce' => Str::uuid()->toString(), 'attachments' => [$oversized],
    ], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->assertDatabaseCount('conversation_attachment_sends', 0);
});

it('rejects a reservation that would exceed the sender retained quota', function () {
    Storage::fake('conversation_attachments');
    config(['conversation_attachments.max_retained_bytes_per_sender' => 1]);
    [$sender, , $conversation] = conversationAttachmentPair();

    $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), [
        'nonce' => Str::uuid()->toString(), 'attachments' => [conversationAttachmentImage()],
    ], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->assertDatabaseCount('conversation_attachment_sends', 0);
});

it('does not route pending request attachments through the conversation message path', function () {
    Storage::fake('conversation_attachments');
    [$sender] = conversationAttachmentPair();
    $recipient = User::factory()->create();

    $this->actingAs($sender)->post(route('message-requests.store', $recipient), [
        'body' => 'Pending request body',
        'nonce' => Str::uuid()->toString(),
        'attachments' => [conversationAttachmentImage()],
    ])->assertSessionHasErrors('attachments');
    $this->assertDatabaseCount('conversation_requests', 0);
    $this->assertDatabaseCount('conversation_attachment_sends', 0);
});

it('keeps a failed multi-file processing attempt atomic and releases quota only after cleanup', function () {
    Storage::fake('conversation_attachments');
    Queue::fake();
    [$sender, , $conversation] = conversationAttachmentPair();
    $webpPayload = "\0\0\0\x9d\x01\x2a\x01\0\x01\0";
    $webp = 'RIFF'.pack('V', 22).'WEBP'.'VP8 '.pack('V', strlen($webpPayload)).$webpPayload;
    $decoder = new class($webp) extends PhotoDecoder
    {
        public int $calls = 0;

        public function __construct(public string $output) {}

        public function isAvailable(): bool
        {
            return true;
        }

        public function decode(string $inputPath, string $outputPath): int
        {
            $this->calls++;
            if ($this->calls === 2) {
                throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
            }
            file_put_contents($outputPath, $this->output, LOCK_EX);

            return strlen($this->output);
        }
    };
    app()->instance(PhotoDecoder::class, $decoder);
    $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), [
        'nonce' => Str::uuid()->toString(),
        'attachments' => [conversationAttachmentImage(), conversationAttachmentImage()],
    ], ['Accept' => 'application/json'])->assertAccepted();
    $send = ConversationAttachmentSend::sole();

    app(ManageConversationAttachments::class)->process($send->id, $send->attempt_token);

    $send->refresh();
    expect($send->status)->toBe('failed')
        ->and($send->reserved_bytes)->toBe(0)
        ->and($send->charged_bytes)->toBe(0)
        ->and(ConversationMessage::count())->toBe(0);
    foreach ($send->files as $file) {
        Storage::disk('conversation_attachments')->assertMissing($file['inputKey']);
        Storage::disk('conversation_attachments')->assertMissing($file['outputKey']);
    }
});

it('rechecks conversation access after every file has been processed', function () {
    Storage::fake('conversation_attachments');
    Queue::fake();
    [$sender, $recipient, $conversation] = conversationAttachmentPair();
    $webpPayload = "\0\0\0\x9d\x01\x2a\x01\0\x01\0";
    $webp = 'RIFF'.pack('V', 22).'WEBP'.'VP8 '.pack('V', strlen($webpPayload)).$webpPayload;
    $decoder = new class($sender, $recipient, $webp) extends PhotoDecoder
    {
        public function __construct(public User $sender, public User $recipient, public string $output) {}

        public function isAvailable(): bool
        {
            return true;
        }

        public function decode(string $inputPath, string $outputPath): int
        {
            Follow::query()->where('follower_id', $this->sender->id)
                ->where('followed_id', $this->recipient->id)->delete();
            file_put_contents($outputPath, $this->output, LOCK_EX);

            return strlen($this->output);
        }
    };
    app()->instance(PhotoDecoder::class, $decoder);
    $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), [
        'body' => 'caption',
        'nonce' => Str::uuid()->toString(),
        'attachments' => [conversationAttachmentImage()],
    ], ['Accept' => 'application/json'])->assertAccepted();
    $send = ConversationAttachmentSend::sole();

    app(ManageConversationAttachments::class)->process($send->id, $send->attempt_token);

    $send->refresh();
    expect($send->status)->toBe('failed')
        ->and($send->failure_code)->toBe('access_revoked')
        ->and($send->reserved_bytes)->toBe(0)
        ->and($send->body)->toBeNull()
        ->and(ConversationMessage::count())->toBe(0);
});

it('recovers a queued send whose database queue item was lost', function () {
    Storage::fake('conversation_attachments');
    Queue::fake();
    [$sender, , $conversation] = conversationAttachmentPair();
    $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), [
        'nonce' => Str::uuid()->toString(), 'attachments' => [conversationAttachmentImage()],
    ], ['Accept' => 'application/json'])->assertAccepted();
    $send = ConversationAttachmentSend::sole();
    $this->travel(31)->minutes();

    app(ManageConversationAttachments::class)->reconcile();

    $send->refresh();
    expect($send->status)->toBe('failed')->and($send->reserved_bytes)->toBe(0);
    Storage::disk('conversation_attachments')->assertMissing($send->files[0]['inputKey']);
});

it('keeps quota reserved when private file deletion cannot be confirmed', function () {
    $realDisk = Storage::fake('conversation_attachments');
    [$sender, , $conversation] = conversationAttachmentPair();
    $token = Str::uuid()->toString();
    $send = ConversationAttachmentSend::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'nonce' => Str::uuid()->toString(),
        'request_fingerprint' => hash('sha256', 'cleanup debt'),
        'attempt_token' => $token,
        'status' => 'failed',
        'body' => null,
        'files' => [['inputKey' => $token.'/0-input', 'outputKey' => $token.'/0-output.webp']],
        'reserved_bytes' => 100,
        'charged_bytes' => 0,
        'cleanup_due_at' => now(),
        'cleanup_completed_at' => null,
    ]);
    $inputKey = $token.'/0-input';
    $outputKey = $token.'/0-output.webp';
    $realDisk->put($inputKey, 'private input');
    $realDisk->put($outputKey, 'private output');
    $directory = $realDisk->path($token);
    chmod($directory, 0000);

    try {
        app(ManageConversationAttachments::class)->failAndCleanup($send->id, $token, 'processing_failed');

        $send->refresh();
        expect($send->status)->toBe('failed')
            ->and($send->reserved_bytes)->toBe(100)
            ->and($send->cleanup_completed_at)->toBeNull()
            ->and($send->cleanup_due_at)->toBeGreaterThan(now());
    } finally {
        chmod($directory, 0700);
        $realDisk->deleteDirectory($token);
    }
    $guardDirectory = $realDisk->path('.locks');
    @unlink($guardDirectory.'/'.($send->id % config('conversation_attachments.max_active_operations')).'.guard');
    @rmdir($guardDirectory);
});

it('delivers media only to currently authorized participants and hides it after access is revoked', function () {
    Storage::fake('conversation_attachments');
    [$sender, $recipient, $conversation] = conversationAttachmentPair();
    $nonce = Str::uuid()->toString();
    $token = Str::uuid()->toString();
    $message = ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'body' => '',
        'client_nonce' => $nonce,
    ]);
    $send = ConversationAttachmentSend::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'nonce' => $nonce,
        'request_fingerprint' => hash('sha256', 'synthetic sent attachment'),
        'attempt_token' => $token,
        'status' => 'sent',
        'body' => '',
        'files' => [[
            'kind' => 'image', 'inputKey' => 'gone-input', 'outputKey' => $token.'/0-output.webp',
            'inputBytes' => 1, 'bytes' => 30, 'width' => 1, 'height' => 1, 'durationSeconds' => null,
        ]],
        'reserved_bytes' => 0,
        'charged_bytes' => 30,
        'message_id' => $message->id,
        'cleanup_completed_at' => now(),
    ]);
    $webpPayload = "\0\0\0\x9d\x01\x2a\x01\0\x01\0";
    $webp = 'RIFF'.pack('V', 22).'WEBP'.'VP8 '.pack('V', strlen($webpPayload)).$webpPayload;
    Storage::disk('conversation_attachments')->put($send->files[0]['outputKey'], $webp);
    $url = route('messages.attachments.media', ['send' => $send->id, 'index' => 0]);

    $this->actingAs($recipient)->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    $this->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/30');
    $this->head($url)->assertOk();

    $disk = Storage::disk('conversation_attachments');
    $storedPath = $disk->path($send->files[0]['outputKey']);
    $disk->put('outside-attempt.webp', $webp);
    unlink($storedPath);
    symlink($disk->path('outside-attempt.webp'), $storedPath);
    $this->get($url)->assertNotFound();
    unlink($storedPath);
    $disk->put($send->files[0]['outputKey'], $webp);

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get($url)->assertNotFound();
    Follow::query()->where('follower_id', $sender->id)->where('followed_id', $recipient->id)->delete();
    $this->actingAs($recipient)->get($url)->assertNotFound();
    $this->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.messages.0.body', '')
        ->where('thread.messages.0.attachments', [])
        ->where('thread.messages.0.attachmentsHidden', true));
});

it('returns active attachment status only to its sender and current conversation members', function () {
    Storage::fake('conversation_attachments');
    Queue::fake();
    [$sender, $recipient, $conversation] = conversationAttachmentPair();
    $nonce = Str::uuid()->toString();
    $this->actingAs($sender)->post(route('messages.attachments.store', $conversation), [
        'nonce' => $nonce, 'attachments' => [conversationAttachmentImage()],
    ], ['Accept' => 'application/json'])->assertAccepted();

    $this->getJson(route('messages.attachments.status', ['conversation' => $conversation->id, 'nonce' => $nonce]))
        ->assertOk()->assertJsonPath('status', 'processing');
    $this->actingAs($recipient)->getJson(route('messages.attachments.status', ['conversation' => $conversation->id, 'nonce' => $nonce]))
        ->assertNotFound();
    $this->actingAs($sender)->get(route('messages.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('thread.pendingAttachmentSend.nonce', $nonce)
        ->where('thread.attachmentCapabilities.maxFiles', 2)
        ->where('thread.attachmentCapabilities.quotaBytes', 1_000_000_000));
});

it('creates attachment send factory records inside a participant conversation', function () {
    $send = ConversationAttachmentSend::factory()->create();

    expect($send->conversation->participants()->where('user_id', $send->sender_id)->exists())->toBeTrue();
});
