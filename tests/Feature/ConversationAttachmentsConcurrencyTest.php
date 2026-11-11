<?php

use App\Actions\ManageConversation;
use App\Actions\ManageConversationAttachments;
use App\Models\ConversationAttachmentSend;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('admits no more than four concurrent senders to attachment staging on PostgreSQL', function () {
    if (getenv('MOMENTO_CONVERSATION_ATTACHMENT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly enabled isolated PostgreSQL concurrency fixture.');
    }

    $pairs = [];
    for ($index = 0; $index < 5; $index++) {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Follow::factory()->approved()->create(['follower_id' => $sender->id, 'followed_id' => $recipient->id]);
        Follow::factory()->approved()->create(['follower_id' => $recipient->id, 'followed_id' => $sender->id]);
        $conversation = app(ManageConversation::class)->createPair($sender->id, $recipient->id);
        $pairs[] = [$sender->id, $recipient->id, $conversation->id];
    }
    $senderIds = array_column($pairs, 0);
    $userIds = array_merge(array_column($pairs, 0), array_column($pairs, 1));
    DB::commit();

    $directory = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).'/momento-chat-attachments-'.bin2hex(random_bytes(8));
    $storageRoot = $directory.'/private';
    mkdir($storageRoot, 0700, true);
    $inputPath = $directory.'/source.jpg';
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//2Q==');
    file_put_contents($inputPath, $jpeg);

    try {
        $tasks = [];
        foreach ($pairs as [$senderId, , $conversationId]) {
            $tasks[] = Closure::bind(static function () use ($senderId, $conversationId, $inputPath, $storageRoot): string {
                config(['filesystems.disks.conversation_attachments.root' => $storageRoot]);
                Storage::forgetDisk('conversation_attachments');
                try {
                    app(ManageConversationAttachments::class)->submit(
                        User::query()->findOrFail($senderId),
                        $conversationId,
                        null,
                        Str::uuid()->toString(),
                        [new UploadedFile($inputPath, 'source.jpg', 'image/jpeg', null, true)],
                    );

                    return 'admitted';
                } catch (ValidationException $exception) {
                    $message = implode(' ', array_merge(...array_values($exception->errors())));

                    return str_contains($message, 'Attachment processing is busy') ? 'global-capacity' : 'unexpected-validation';
                } catch (Throwable $exception) {
                    return 'unexpected:'.class_basename($exception);
                }
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 45);
        expect(array_count_values($results))->toEqual(['admitted' => 4, 'global-capacity' => 1]);
        expect(ConversationAttachmentSend::whereIn('sender_id', $senderIds)->count())->toBe(4)
            ->and(ConversationAttachmentSend::whereIn('sender_id', $senderIds)->sum('reserved_bytes'))
            ->toBeLessThanOrEqual(4 * (config('conversation_attachments.image_max_bytes') + config('conversation_attachments.image_max_output_bytes')));
    } finally {
        $sends = ConversationAttachmentSend::query()->whereIn('sender_id', $senderIds)->get();
        foreach ($sends as $send) {
            File::deleteDirectory($storageRoot.'/'.$send->attempt_token);
        }
        ConversationAttachmentSend::query()->whereIn('sender_id', $senderIds)->delete();
        DB::table('jobs')->where('queue', 'conversation-attachments')->delete();
        User::query()->whereKey($userIds)->delete();
        File::deleteDirectory($directory);
        DB::beginTransaction();
    }
});

it('serializes concurrent sends from one sender at the retained-quota boundary', function () {
    if (getenv('MOMENTO_CONVERSATION_ATTACHMENT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly enabled isolated PostgreSQL concurrency fixture.');
    }

    $sender = User::factory()->create();
    $pairs = [];
    for ($index = 0; $index < 2; $index++) {
        $recipient = User::factory()->create();
        Follow::factory()->approved()->create(['follower_id' => $sender->id, 'followed_id' => $recipient->id]);
        Follow::factory()->approved()->create(['follower_id' => $recipient->id, 'followed_id' => $sender->id]);
        $conversation = app(ManageConversation::class)->createPair($sender->id, $recipient->id);
        $pairs[] = [$sender->id, $recipient->id, $conversation->id];
    }
    DB::commit();

    $directory = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).'/momento-chat-sender-quota-'.bin2hex(random_bytes(8));
    $storageRoot = $directory.'/private';
    mkdir($storageRoot, 0700, true);
    $inputPath = $directory.'/source.jpg';
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//2Q==');
    file_put_contents($inputPath, $jpeg);
    $quotaLimit = filesize($inputPath) + config('conversation_attachments.image_max_output_bytes');
    config(['conversation_attachments.max_retained_bytes_per_sender' => $quotaLimit]);

    try {
        $tasks = [];
        foreach ($pairs as [$senderId, , $conversationId]) {
            $tasks[] = Closure::bind(static function () use ($senderId, $conversationId, $inputPath, $storageRoot, $quotaLimit): string {
                config([
                    'filesystems.disks.conversation_attachments.root' => $storageRoot,
                    'conversation_attachments.max_retained_bytes_per_sender' => $quotaLimit,
                ]);
                Storage::forgetDisk('conversation_attachments');
                try {
                    app(ManageConversationAttachments::class)->submit(
                        User::query()->findOrFail($senderId),
                        $conversationId,
                        null,
                        Str::uuid()->toString(),
                        [new UploadedFile($inputPath, 'source.jpg', 'image/jpeg', null, true)],
                    );

                    return 'admitted';
                } catch (ValidationException $exception) {
                    $message = implode(' ', array_merge(...array_values($exception->errors())));

                    return str_contains($message, 'current attachment message first') ? 'sender-active'
                        : (str_contains($message, 'storage is full') ? 'quota' : 'unexpected-validation');
                } catch (Throwable $exception) {
                    return 'unexpected:'.class_basename($exception);
                }
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 45);
        expect(array_count_values($results))->toEqual(['admitted' => 1, 'sender-active' => 1]);
        $send = ConversationAttachmentSend::query()->where('sender_id', $sender->id)->sole();
        expect($send->reserved_bytes)->toBe($quotaLimit)
            ->and(ConversationAttachmentSend::query()->where('sender_id', $sender->id)->sum(DB::raw('reserved_bytes + charged_bytes')))
            ->toBeLessThanOrEqual($quotaLimit);
    } finally {
        $sends = ConversationAttachmentSend::query()->where('sender_id', $sender->id)->get();
        foreach ($sends as $send) {
            File::deleteDirectory($storageRoot.'/'.$send->attempt_token);
        }
        ConversationAttachmentSend::query()->where('sender_id', $sender->id)->delete();
        DB::table('jobs')->where('queue', 'conversation-attachments')->delete();
        User::query()->whereKey(array_merge([$sender->id], array_column($pairs, 1)))->delete();
        File::deleteDirectory($directory);
        DB::beginTransaction();
    }
});

it('creates one attempt when a sender concurrently replays one attachment nonce', function () {
    if (getenv('MOMENTO_CONVERSATION_ATTACHMENT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly enabled isolated PostgreSQL concurrency fixture.');
    }

    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    Follow::factory()->approved()->create(['follower_id' => $sender->id, 'followed_id' => $recipient->id]);
    Follow::factory()->approved()->create(['follower_id' => $recipient->id, 'followed_id' => $sender->id]);
    $conversation = app(ManageConversation::class)->createPair($sender->id, $recipient->id);
    DB::commit();
    $senderId = $sender->id;
    $conversationId = $conversation->id;

    $directory = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).'/momento-chat-attachment-nonce-'.bin2hex(random_bytes(8));
    $storageRoot = $directory.'/private';
    mkdir($storageRoot, 0700, true);
    $inputPath = $directory.'/source.jpg';
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//2Q==');
    file_put_contents($inputPath, $jpeg);
    $nonce = Str::uuid()->toString();

    try {
        $tasks = [];
        for ($index = 0; $index < 2; $index++) {
            $tasks[] = Closure::bind(static function () use ($senderId, $conversationId, $nonce, $inputPath, $storageRoot): int {
                config(['filesystems.disks.conversation_attachments.root' => $storageRoot]);
                Storage::forgetDisk('conversation_attachments');

                return app(ManageConversationAttachments::class)->submit(
                    User::query()->findOrFail($senderId),
                    $conversationId,
                    'same attachment draft',
                    $nonce,
                    [new UploadedFile($inputPath, 'source.jpg', 'image/jpeg', null, true)],
                )->id;
            }, null, null);
        }

        $resultIds = Concurrency::driver('process')->run($tasks, timeout: 45);
        expect(array_unique($resultIds))->toHaveCount(1);
        $send = ConversationAttachmentSend::query()->where('conversation_id', $conversationId)
            ->where('sender_id', $senderId)->where('nonce', $nonce)->sole();
        expect($send->status)->toBe('queued')
            ->and($send->reserved_bytes)->toBeGreaterThan(0)
            ->and(DB::table('jobs')->where('queue', 'conversation-attachments')->count())->toBe(1);
    } finally {
        $send = ConversationAttachmentSend::query()->where('sender_id', $senderId)->first();
        if ($send !== null) {
            File::deleteDirectory($storageRoot.'/'.$send->attempt_token);
        }
        ConversationAttachmentSend::query()->where('sender_id', $senderId)->delete();
        DB::table('jobs')->where('queue', 'conversation-attachments')->delete();
        User::query()->whereKey([$sender->id, $recipient->id])->delete();
        File::deleteDirectory($directory);
        DB::beginTransaction();
    }
});
