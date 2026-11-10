<?php

namespace App\Actions;

use App\Exceptions\ConversationVideoException;
use App\Jobs\ProcessConversationAttachments;
use App\Models\Conversation;
use App\Models\ConversationAttachmentSend;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\ConversationAccess;
use App\Services\ConversationVideoDecoder;
use App\Services\PhotoDecoder;
use App\Services\WebpOutputInspector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ManageConversationAttachments
{
    public const ACTIVE_STATUSES = ['staging', 'queued', 'processing'];

    public function __construct(private ConversationAccess $access) {}

    /** @param array<int, UploadedFile> $uploads */
    public function submit(User $sender, int $conversationId, ?string $body, string $nonce, array $uploads): ConversationAttachmentSend
    {
        if (count($uploads) < 1 || count($uploads) > config('conversation_attachments.max_files')) {
            throw ValidationException::withMessages(['attachments' => 'Choose one or two supported files.']);
        }

        $files = [];
        $fingerprintParts = [$body ?? ''];
        $reservedBytes = 0;
        foreach ($uploads as $upload) {
            $file = $this->describeUpload($upload);
            $files[] = $file;
            $fingerprintParts[] = $file['kind']."\0".$file['sha256'];
            $reservedBytes += $file['inputBytes'] + $file['maxOutputBytes'];
        }
        $fingerprint = hash('sha256', implode("\0", $fingerprintParts));
        $token = (string) Str::uuid();
        foreach ($files as $index => $file) {
            $extension = $file['kind'] === 'image' ? 'webp' : 'mp4';
            $files[$index] = [
                'kind' => $file['kind'],
                'sha256' => $file['sha256'],
                'inputBytes' => $file['inputBytes'],
                'inputKey' => $token.'/'.$index.'-input',
                'outputKey' => $token.'/'.$index.'-output.'.$extension,
            ];
        }

        $send = DB::transaction(function () use ($sender, $conversationId, $body, $nonce, $fingerprint, $files, $reservedBytes, $token): ConversationAttachmentSend {
            $this->lockCapacity();
            $conversation = Conversation::query()->findOrFail($conversationId);
            $members = $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
            $current = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $lockedSender = $members->firstWhere('id', $sender->id);
            abort_unless($lockedSender instanceof User, 404);

            $existing = ConversationAttachmentSend::query()->where('conversation_id', $current->id)
                ->where('sender_id', $lockedSender->id)->where('nonce', $nonce)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new HttpException(409, 'This retry key belongs to different message content.');
                }

                return $existing;
            }

            abort_unless($members->every(fn (User $user): bool => $user->hasVerifiedEmail()), 404);
            $memberId = $lockedSender->id === $current->member_one_id ? $current->member_two_id : $current->member_one_id;
            $member = $members->firstWhere('id', $memberId);
            abort_unless($member instanceof User && $this->access->canSend($lockedSender, $member), 404);

            if (ConversationMessage::query()->where('conversation_id', $current->id)
                ->where('sender_id', $lockedSender->id)->where('client_nonce', $nonce)->exists()) {
                throw new HttpException(409, 'This retry key already belongs to a text message.');
            }

            $activeFromSender = ConversationAttachmentSend::query()->where('sender_id', $lockedSender->id)
                ->whereIn('status', self::ACTIVE_STATUSES)->count();
            if ($activeFromSender >= config('conversation_attachments.max_active_per_sender')) {
                throw ValidationException::withMessages(['attachments' => 'Finish processing your current attachment message first.']);
            }

            $activeCount = ConversationAttachmentSend::query()->whereIn('status', self::ACTIVE_STATUSES)->count();
            if ($activeCount >= config('conversation_attachments.max_active_operations')) {
                throw ValidationException::withMessages(['attachments' => 'Attachment processing is busy. Try again shortly.']);
            }

            $usedBytes = (int) ConversationAttachmentSend::query()->where('sender_id', $lockedSender->id)
                ->selectRaw('COALESCE(SUM(reserved_bytes + charged_bytes), 0) AS retained_bytes')->value('retained_bytes');
            if ($usedBytes + $reservedBytes > config('conversation_attachments.max_retained_bytes_per_sender')) {
                throw ValidationException::withMessages(['attachments' => 'Your retained attachment storage is full.']);
            }

            return ConversationAttachmentSend::query()->create([
                'conversation_id' => $current->id,
                'sender_id' => $lockedSender->id,
                'nonce' => $nonce,
                'request_fingerprint' => $fingerprint,
                'attempt_token' => $token,
                'status' => 'staging',
                'body' => $body,
                'files' => $files,
                'reserved_bytes' => $reservedBytes,
                'charged_bytes' => 0,
            ]);
        }, attempts: 3);

        if ($send->status !== 'staging' || $send->attempt_token !== $token) {
            return $send;
        }

        $guard = $this->acquireOperationGuard($send->id);
        if ($guard === null) {
            $this->failUnstaged($send->id, $token);

            return $send->fresh();
        }

        try {
            foreach ($uploads as $index => $upload) {
                $this->copyIntoPrivateStorage($upload, $files[$index]['inputKey']);
            }
            $this->verifyStagedInputs($files);

            return DB::transaction(function () use ($send, $token): ConversationAttachmentSend {
                $this->lockCapacity();
                $conversation = Conversation::query()->findOrFail($send->conversation_id);
                $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
                Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
                $current = ConversationAttachmentSend::query()->lockForUpdate()->findOrFail($send->id);
                if ($current->attempt_token !== $token || $current->status !== 'staging') {
                    return $current;
                }

                $current->update(['status' => 'queued']);
                ProcessConversationAttachments::dispatch($current->id, $token)
                    ->onConnection('conversation_attachments')->onQueue('conversation-attachments');

                return $current->fresh();
            }, attempts: 3);
        } catch (Throwable) {
            $this->failAndCleanupLocked($send->id, $token, 'storage_unavailable');

            return $send->fresh();
        } finally {
            $this->releaseOperationGuard($guard);
        }
    }

    /** @return array{kind: string, sha256: string, inputBytes: int, maxOutputBytes: int} */
    private function describeUpload(UploadedFile $upload): array
    {
        $bytes = $upload->getSize();
        $path = $upload->getRealPath();
        if (! is_int($bytes) || $bytes < 1 || ! is_string($path) || is_link($path) || ! is_file($path)) {
            throw ValidationException::withMessages(['attachments' => 'Choose valid supported files.']);
        }

        $mime = $upload->getMimeType();
        $imageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/octet-stream'];
        if (in_array($mime, $imageMimes, true) && $bytes <= config('conversation_attachments.image_max_bytes')) {
            $kind = 'image';
            $maxOutputBytes = config('conversation_attachments.image_max_output_bytes');
        } elseif ($mime === 'video/mp4' && $bytes <= config('conversation_attachments.video_max_bytes')) {
            $kind = 'video';
            $maxOutputBytes = config('conversation_attachments.video_max_output_bytes');
        } else {
            throw ValidationException::withMessages(['attachments' => 'Use an accepted image up to 20 MB or MP4 video up to 25 MB.']);
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['attachments' => 'The uploaded file could not be read.']);
        }

        return ['kind' => $kind, 'sha256' => $hash, 'inputBytes' => $bytes, 'maxOutputBytes' => $maxOutputBytes];
    }

    private function copyIntoPrivateStorage(UploadedFile $upload, string $key): void
    {
        $disk = Storage::disk('conversation_attachments');
        $target = $disk->path($key);
        $directory = dirname($target);
        $rootPath = $disk->path('');
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))
            || is_link($directory) || is_link($target)) {
            throw new \RuntimeException('Attachment directory is unavailable.');
        }
        $root = realpath($rootPath);
        if (! is_string($root) || is_link($rootPath) || dirname($directory) !== $root
            || realpath($directory) !== $directory) {
            throw new \RuntimeException('Attachment directory is unavailable.');
        }

        $input = @fopen($upload->getRealPath(), 'rb');
        $output = @fopen($target, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new \RuntimeException('Attachment copy failed.');
        }

        try {
            if (stream_copy_to_stream($input, $output) === false || ! fflush($output)) {
                throw new \RuntimeException('Attachment copy failed.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** @param array<int, array<string, mixed>> $files */
    private function verifyStagedInputs(array $files): void
    {
        $disk = Storage::disk('conversation_attachments');
        foreach ($files as $file) {
            $path = $disk->path((string) $file['inputKey']);
            $this->assertContainedFile($disk->path(''), $path);
            $bytes = filesize($path);
            $hash = hash_file('sha256', $path);
            if (! is_int($bytes) || $bytes !== $file['inputBytes'] || ! is_string($hash)
                || ! hash_equals($file['sha256'], $hash)) {
                throw new \RuntimeException('Staged attachment verification failed.');
            }
        }
    }

    public function process(int $sendId, string $attemptToken): bool
    {
        $guard = $this->acquireOperationGuard($sendId);
        if ($guard === null) {
            return false;
        }

        try {
            $this->processUnderGuard($sendId, $attemptToken);

            return true;
        } finally {
            $this->releaseOperationGuard($guard);
        }
    }

    private function processUnderGuard(int $sendId, string $attemptToken): void
    {
        $send = DB::transaction(function () use ($sendId, $attemptToken): ?ConversationAttachmentSend {
            $this->lockCapacity();
            $send = ConversationAttachmentSend::query()->find($sendId);
            if ($send === null) {
                return null;
            }
            $conversation = Conversation::query()->findOrFail($send->conversation_id);
            $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
            Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $current = ConversationAttachmentSend::query()->lockForUpdate()->findOrFail($sendId);
            if ($current->attempt_token !== $attemptToken
                || ! in_array($current->status, ['queued', 'processing'], true)) {
                return null;
            }
            if ($current->status === 'processing' && $current->processing_started_at !== null
                && $current->processing_started_at->gt(now()->subSeconds(300))) {
                return null;
            }
            $current->update(['status' => 'processing', 'processing_started_at' => now()]);

            return $current->fresh();
        }, attempts: 3);
        if (! $send instanceof ConversationAttachmentSend) {
            return;
        }

        $files = $send->files;
        try {
            foreach ($files as $index => $file) {
                $videoMetadata = $this->processFile($file);
                $files[$index] = $this->inspectProcessedFile($file, $videoMetadata);
            }
            if (! $this->deleteInputs($files)) {
                throw new \RuntimeException('Input cleanup is pending.');
            }

            $message = DB::transaction(function () use ($send, $attemptToken, $files): ?ConversationMessage {
                $this->lockCapacity();
                $conversation = Conversation::query()->findOrFail($send->conversation_id);
                $members = $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
                $currentConversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
                $current = ConversationAttachmentSend::query()->lockForUpdate()->findOrFail($send->id);
                if ($current->attempt_token !== $attemptToken || $current->status !== 'processing') {
                    return null;
                }

                $sender = $members->firstWhere('id', $current->sender_id);
                $recipientId = $current->sender_id === $currentConversation->member_one_id
                    ? $currentConversation->member_two_id : $currentConversation->member_one_id;
                $recipient = $members->firstWhere('id', $recipientId);
                if (! $sender instanceof User || ! $recipient instanceof User
                    || ! $members->every(fn (User $user): bool => $user->hasVerifiedEmail())
                    || ! $this->access->canSend($sender, $recipient)) {
                    $current->update(['status' => 'failed', 'failure_code' => 'access_revoked', 'reserved_bytes' => $current->reserved_bytes,
                        'cleanup_due_at' => now(), 'files' => $files]);

                    return null;
                }

                $body = $current->body ?? '';
                if ($body === '' && $files === []) {
                    $current->update(['status' => 'failed', 'failure_code' => 'empty_message', 'cleanup_due_at' => now(), 'files' => $files]);

                    return null;
                }

                $message = ConversationMessage::query()->create([
                    'conversation_id' => $currentConversation->id,
                    'sender_id' => $current->sender_id,
                    'body' => $body,
                    'client_nonce' => $current->nonce,
                ]);
                $charged = array_sum(array_column($files, 'bytes'));
                $current->update([
                    'status' => 'sent',
                    'body' => null,
                    'files' => $files,
                    'reserved_bytes' => 0,
                    'charged_bytes' => $charged,
                    'message_id' => $message->id,
                    'failure_code' => null,
                    'cleanup_due_at' => null,
                    'cleanup_completed_at' => now(),
                ]);

                return $message;
            }, attempts: 3);

            if ($message === null) {
                $this->failAndCleanupLocked($sendId, $attemptToken, 'access_revoked');
            }
        } catch (Throwable) {
            $this->failAndCleanupLocked($sendId, $attemptToken, 'processing_failed');
        }
    }

    /** @return array{resource, string}|null */
    public function acquireOperationGuard(int $sendId): ?array
    {
        // shortcut: Fixed shards can require a busy retry below capacity; assign free slots if collisions become frequent.
        $shard = $sendId % config('conversation_attachments.max_active_operations');
        $disk = Storage::disk('conversation_attachments');
        $rootPath = $disk->path('');
        $path = $disk->path('.locks/'.$shard.'.guard');
        $directory = dirname($path);
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))
            || is_link($directory) || is_link($path)) {
            return null;
        }
        $root = realpath($rootPath);
        if (! is_string($root) || is_link($rootPath) || dirname($directory) !== $root
            || realpath($directory) !== $directory) {
            return null;
        }
        $stream = @fopen($path, 'c');
        if (! is_resource($stream) || ! flock($stream, LOCK_EX | LOCK_NB)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return null;
        }

        return [$stream, $path];
    }

    /** @param array{resource, string} $guard */
    public function releaseOperationGuard(array $guard): void
    {
        flock($guard[0], LOCK_UN);
        fclose($guard[0]);
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{bytes: int, width: int, height: int, durationSeconds: float}|null
     */
    private function processFile(array $file): ?array
    {
        $disk = Storage::disk('conversation_attachments');
        $inputPath = $disk->path((string) $file['inputKey']);
        $outputPath = $disk->path((string) $file['outputKey']);
        $this->assertContainedFile($disk->path(''), $inputPath);
        if ($file['kind'] === 'image') {
            $decoder = app(PhotoDecoder::class);
            if (! $decoder->isAvailable()) {
                throw new \RuntimeException('Image processing unavailable.');
            }
            $decoder->decode($inputPath, $outputPath);
            app(WebpOutputInspector::class)->inspect($outputPath);

            return null;
        }

        $decoder = app(ConversationVideoDecoder::class);
        if (! $decoder->isAvailable()) {
            throw new ConversationVideoException;
        }

        return $decoder->decode($inputPath, $outputPath);
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  array{bytes: int, width: int, height: int, durationSeconds: float}|null  $videoMetadata
     * @return array<string, mixed>
     */
    private function inspectProcessedFile(array $file, ?array $videoMetadata = null): array
    {
        $disk = Storage::disk('conversation_attachments');
        $path = $disk->path((string) $file['outputKey']);
        $this->assertContainedFile($disk->path(''), $path);
        $bytes = filesize($path);
        if (! is_int($bytes) || $bytes < 1) {
            throw new \RuntimeException('Processed attachment is unavailable.');
        }

        if ($file['kind'] === 'image') {
            $metadata = app(WebpOutputInspector::class)->inspect($path);
            if ($bytes > config('conversation_attachments.image_max_output_bytes')) {
                throw new \RuntimeException('Processed image exceeded its bound.');
            }

            return [...$file, 'bytes' => $bytes, 'width' => $metadata['width'], 'height' => $metadata['height'], 'durationSeconds' => null];
        }

        if ($bytes > config('conversation_attachments.video_max_output_bytes')) {
            throw new ConversationVideoException;
        }
        $metadata = $videoMetadata;
        if ($metadata === null || $metadata['bytes'] !== $bytes || $metadata['durationSeconds'] > config('conversation_attachments.video_max_duration_seconds')) {
            throw new ConversationVideoException;
        }

        return [...$file, 'bytes' => $bytes, 'width' => $metadata['width'], 'height' => $metadata['height'], 'durationSeconds' => $metadata['durationSeconds']];
    }

    /** @param array<int, array<string, mixed>> $files */
    private function deleteInputs(array $files): bool
    {
        $disk = Storage::disk('conversation_attachments');
        $clean = true;
        foreach ($files as $file) {
            $key = (string) $file['inputKey'];
            if ($this->artifactAbsent($key)) {
                continue;
            }
            if (! $this->artifactParentIsSafe($key)) {
                $clean = false;

                continue;
            }
            try {
                if (! $disk->delete($key) && ! $this->artifactAbsent($key)) {
                    $clean = false;
                }
                if (! $this->artifactAbsent($key)) {
                    $clean = false;
                }
            } catch (Throwable) {
                $clean = false;
            }
        }

        return $clean;
    }

    public function failAndCleanup(int $sendId, string $attemptToken, string $failureCode): void
    {
        $guard = $this->acquireOperationGuard($sendId);
        if ($guard === null) {
            return;
        }

        try {
            $this->failAndCleanupLocked($sendId, $attemptToken, $failureCode);
        } finally {
            $this->releaseOperationGuard($guard);
        }
    }

    private function failAndCleanupLocked(int $sendId, string $attemptToken, string $failureCode): void
    {
        $send = ConversationAttachmentSend::query()->find($sendId);
        if ($send === null || $send->attempt_token !== $attemptToken || $send->status === 'sent'
            || ($send->status === 'failed' && $send->cleanup_completed_at !== null)) {
            return;
        }
        $clean = $this->deleteFiles($send->files);
        if ($clean) {
            $clean = $this->removeAttemptDirectory($attemptToken);
        }
        if (! $clean) {
            Log::warning('Conversation attachment cleanup remains pending.', ['attachment_send_id' => $sendId]);
        }
        DB::transaction(function () use ($sendId, $attemptToken, $failureCode, $clean): void {
            $this->lockCapacity();
            $send = ConversationAttachmentSend::query()->find($sendId);
            if ($send === null) {
                return;
            }
            $conversation = Conversation::query()->findOrFail($send->conversation_id);
            $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
            Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $current = ConversationAttachmentSend::query()->lockForUpdate()->find($sendId);
            if ($current === null || $current->attempt_token !== $attemptToken || $current->status === 'sent'
                || ($current->status === 'failed' && $current->cleanup_completed_at !== null)) {
                return;
            }
            $current->update([
                'status' => 'failed',
                'body' => null,
                'failure_code' => $failureCode,
                'reserved_bytes' => $clean ? 0 : $current->reserved_bytes,
                'charged_bytes' => $clean ? 0 : $current->charged_bytes,
                'cleanup_due_at' => $clean ? null : now()->addSeconds(config('conversation_attachments.cleanup_retry_seconds')),
                'cleanup_completed_at' => $clean ? now() : null,
            ]);
        }, attempts: 3);
    }

    private function failUnstaged(int $sendId, string $attemptToken): void
    {
        DB::transaction(function () use ($sendId, $attemptToken): void {
            $this->lockCapacity();
            $send = ConversationAttachmentSend::query()->find($sendId);
            if ($send === null) {
                return;
            }
            $conversation = Conversation::query()->findOrFail($send->conversation_id);
            $this->lockMembers($conversation->member_one_id, $conversation->member_two_id);
            Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $current = ConversationAttachmentSend::query()->lockForUpdate()->find($sendId);
            if ($current !== null && $current->attempt_token === $attemptToken && $current->status === 'staging') {
                $current->update([
                    'status' => 'failed',
                    'body' => null,
                    'failure_code' => 'storage_busy',
                    'reserved_bytes' => 0,
                    'cleanup_due_at' => null,
                    'cleanup_completed_at' => now(),
                ]);
            }
        }, attempts: 3);
    }

    /** @param array<int, array<string, mixed>> $files */
    private function deleteFiles(array $files): bool
    {
        $disk = Storage::disk('conversation_attachments');
        $clean = true;
        foreach ($files as $file) {
            foreach (['inputKey', 'outputKey'] as $keyName) {
                $key = $file[$keyName] ?? null;
                if (! is_string($key) || $this->artifactAbsent($key)) {
                    continue;
                }
                if (! $this->artifactParentIsSafe($key)) {
                    $clean = false;

                    continue;
                }
                try {
                    if (! $disk->delete($key) && ! $this->artifactAbsent($key)) {
                        $clean = false;
                    }
                    if (! $this->artifactAbsent($key)) {
                        $clean = false;
                    }
                } catch (Throwable) {
                    $clean = false;
                }
            }
        }

        return $clean;
    }

    private function artifactParentIsSafe(string $key): bool
    {
        if (preg_match('/\A[0-9a-f-]{36}\/[01]-(?:input|output\.(?:webp|mp4))\z/iD', $key) !== 1) {
            return false;
        }
        $disk = Storage::disk('conversation_attachments');
        $rootPath = $disk->path('');
        $root = realpath($rootPath);
        $directory = dirname($disk->path($key));
        $realDirectory = realpath($directory);

        return is_string($root) && ! is_link($rootPath) && is_dir($root)
            && is_readable($root) && is_executable($root)
            && is_string($realDirectory) && $realDirectory === $directory
            && str_starts_with($realDirectory, $root.DIRECTORY_SEPARATOR)
            && is_dir($realDirectory) && ! is_link($directory)
            && is_readable($realDirectory) && is_executable($realDirectory)
            && is_array(@scandir($realDirectory));
    }

    /** @phpstan-impure */
    private function artifactAbsent(string $key): bool
    {
        if (preg_match('/\A([0-9a-f-]{36})\/[01]-(?:input|output\.(?:webp|mp4))\z/iD', $key, $matches) !== 1) {
            return false;
        }
        $disk = Storage::disk('conversation_attachments');
        $rootPath = $disk->path('');
        $root = realpath($rootPath);
        if (! is_string($root) || is_link($rootPath) || ! is_dir($root)
            || ! is_readable($root) || ! is_executable($root)) {
            return false;
        }

        $directory = $disk->path($matches[1]);
        if (! file_exists($directory) && ! is_link($directory)) {
            $entries = @scandir($root);

            return is_array($entries) && ! in_array($matches[1], $entries, true);
        }
        if (! $this->artifactParentIsSafe($key)) {
            return false;
        }
        $path = $disk->path($key);
        $entries = @scandir(dirname($path));

        return is_array($entries) && ! in_array(basename($path), $entries, true) && @lstat($path) === false;
    }

    private function removeAttemptDirectory(string $attemptToken): bool
    {
        if (preg_match('/\A[0-9a-f-]{36}\z/iD', $attemptToken) !== 1) {
            return false;
        }
        $path = Storage::disk('conversation_attachments')->path($attemptToken);
        $rootPath = Storage::disk('conversation_attachments')->path('');
        $root = realpath($rootPath);
        if (! is_string($root) || is_link($rootPath) || ! is_dir($root)
            || ! is_readable($root) || ! is_executable($root)) {
            return false;
        }
        if (! file_exists($path) && ! is_link($path)) {
            $entries = @scandir($root);

            return is_array($entries) && ! in_array($attemptToken, $entries, true);
        }
        if (! is_dir($path) || is_link($path) || realpath($path) !== $path
            || dirname($path) !== $root || ! is_readable($path) || ! is_executable($path)) {
            return false;
        }
        $entries = @scandir($path);
        if (! is_array($entries) || count($entries) !== 2) {
            return false;
        }

        return @rmdir($path) || ! file_exists($path);
    }

    public function reconcile(): int
    {
        $staleStage = now()->subSeconds(config('conversation_attachments.staging_recovery_after_seconds'));
        $staleQueued = now()->subSeconds(config('conversation_attachments.queued_recovery_after_seconds'));
        $staleProcessing = now()->subSeconds(config('conversation_attachments.processing_recovery_after_seconds'));
        $sends = ConversationAttachmentSend::query()->where(function ($query) use ($staleStage, $staleQueued, $staleProcessing): void {
            $query->where(function ($query): void {
                $query->where('status', 'failed')->whereNotNull('cleanup_due_at')->where('cleanup_due_at', '<=', now());
            })->orWhere(function ($query) use ($staleStage): void {
                $query->where('status', 'staging')->where('created_at', '<=', $staleStage);
            })->orWhere(function ($query) use ($staleQueued): void {
                $query->where('status', 'queued')->where('updated_at', '<=', $staleQueued);
            })->orWhere(function ($query) use ($staleProcessing): void {
                $query->where('status', 'processing')->where('processing_started_at', '<=', $staleProcessing);
            });
        })->orderBy('id')->limit(100)->get();
        $count = 0;
        foreach ($sends as $send) {
            $this->failAndCleanup($send->id, $send->attempt_token, 'processing_failed');
            $count++;
        }

        return $count;
    }

    /** @return array{images: bool, video: bool, maxFiles: int, imageMaxBytes: int, videoMaxBytes: int, maxVideoSeconds: int, quotaBytes: int} */
    public function capabilities(): array
    {
        return [
            'images' => app(PhotoDecoder::class)->isAvailable(),
            'video' => app(ConversationVideoDecoder::class)->isAvailable(),
            'maxFiles' => config('conversation_attachments.max_files'),
            'imageMaxBytes' => config('conversation_attachments.image_max_bytes'),
            'videoMaxBytes' => config('conversation_attachments.video_max_bytes'),
            'maxVideoSeconds' => config('conversation_attachments.video_max_duration_seconds'),
            'quotaBytes' => config('conversation_attachments.max_retained_bytes_per_sender'),
        ];
    }

    /** @param array<int, int> $messageIds
     * @return array<int, array{attachments: array<int, array<string, mixed>>, attachmentsHidden: bool}>
     */
    public function projection(array $messageIds, bool $visible): array
    {
        $result = [];
        $sends = ConversationAttachmentSend::query()->whereIn('message_id', $messageIds)
            ->where('status', 'sent')->get()->keyBy('message_id');
        foreach ($messageIds as $messageId) {
            $send = $sends->get($messageId);
            $attachments = [];
            if ($visible && $send instanceof ConversationAttachmentSend) {
                foreach ($send->files as $index => $file) {
                    $attachments[] = [
                        'kind' => $file['kind'],
                        'url' => route('messages.attachments.media', ['send' => $send->id, 'index' => $index]),
                        'width' => $file['width'],
                        'height' => $file['height'],
                        'bytes' => $file['bytes'],
                        'durationSeconds' => $file['durationSeconds'],
                    ];
                }
            }
            $result[$messageId] = ['attachments' => $attachments, 'attachmentsHidden' => $send !== null && ! $visible];
        }

        return $result;
    }

    public function pendingFor(User $sender, Conversation $conversation): ?ConversationAttachmentSend
    {
        return ConversationAttachmentSend::query()->where('conversation_id', $conversation->id)
            ->where('sender_id', $sender->id)->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('id')->first();
    }

    /** @return Collection<int, User> */
    private function lockMembers(int $first, int $second): Collection
    {
        $members = User::query()->whereKey([$first, $second])->orderBy('id')->lockForUpdate()->get();
        abort_unless($members->count() === 2, 404);

        return $members;
    }

    private function lockCapacity(): void
    {
        if (DB::table('conversation_attachment_capacity')->where('id', 1)->lockForUpdate()->first() === null) {
            throw new \RuntimeException('Attachment capacity guard is missing.');
        }
    }

    private function assertContainedFile(string $rootPath, string $path): void
    {
        $root = realpath($rootPath);
        $directory = dirname($path);
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        abort_unless(is_string($root) && ! is_link($rootPath) && is_string($realDirectory)
            && dirname($directory) === $root && $realDirectory === $directory && ! is_link($directory)
            && is_string($realPath) && $realPath === $path
            && str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)
            && is_file($realPath) && ! is_link($path), 404);
    }
}
