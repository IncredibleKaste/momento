<?php

namespace App\Actions;

use App\Enums\PhotoPostFailure;
use App\Enums\PhotoPostStatus;
use App\Exceptions\PhotoDecoderException;
use App\Jobs\ProcessPhotoPost;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoDecoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ManagePhotoPosts
{
    public function submit(User $owner, UploadedFile $photo, ?string $caption, string $idempotencyKey, ?string $location = null): PhotoPost
    {
        $location = $location === null ? null : trim($location);
        $location = $location === '' ? null : $location;
        $bytes = $photo->getSize();
        if (! is_int($bytes) || $bytes < 1 || $bytes > config('photo_processing.max_input_bytes')) {
            throw ValidationException::withMessages(['photo' => 'Choose a photo no larger than 20 MB.']);
        }

        $path = $photo->getRealPath();
        if (! is_string($path) || is_link($path) || ! is_file($path)) {
            throw new PhotoDecoderException(PhotoPostFailure::InvalidPhoto);
        }

        $contentHash = hash_file('sha256', $path);
        if (! is_string($contentHash)) {
            throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
        }
        $fingerprintInput = $contentHash."\0".($caption ?? '');
        if ($location !== null) {
            $fingerprintInput .= "\0".$location;
        }
        $fingerprint = hash('sha256', $fingerprintInput);

        $attemptToken = (string) Str::uuid();
        $disk = Storage::disk('photo_posts');
        $rawKey = $attemptToken.'/original';
        $outputKey = $attemptToken.'/processed.webp';

        $post = DB::transaction(function () use ($owner, $idempotencyKey, $fingerprint, $attemptToken, $rawKey, $outputKey, $bytes, $caption, $location): PhotoPost {
            DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
            $lockedOwner = User::query()->lockForUpdate()->findOrFail($owner->id);
            $existing = PhotoPost::query()->where('user_id', $lockedOwner->id)
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->status === PhotoPostStatus::Deleted) {
                    throw new HttpException(409, 'This photo post was removed. Start a new post with a new upload key.');
                }
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new HttpException(409, 'This retry key belongs to different photo, caption, or location content.');
                }
                if (! $existing->status->isFailedOrCancelled()) {
                    return $existing;
                }
                if ($existing->cleanup_completed_at === null || $existing->staging_bytes_charged !== 0
                    || $existing->processed_bytes_charged !== 0) {
                    throw ValidationException::withMessages([
                        'photo' => 'Cleanup is still pending. Retry shortly with the same photo, caption, and location.',
                    ]);
                }
            }

            if (! app(PhotoDecoder::class)->isAvailable()) {
                throw new PhotoDecoderException(PhotoPostFailure::DecoderUnavailable);
            }

            $active = PhotoPost::query()->where('user_id', $lockedOwner->id)
                ->where(function ($query): void {
                    $query->whereIn('status', ['staging', 'queued', 'processing', 'cancel_requested'])
                        ->orWhere('staging_bytes_charged', '>', 0);
                })->exists();
            if ($active) {
                throw ValidationException::withMessages(['photo' => 'Finish or cancel your current photo post before starting another.']);
            }

            $capacity = DB::table('photo_posts')
                ->where(function ($query): void {
                    $query->whereIn('status', ['staging', 'queued', 'processing', 'cancel_requested'])
                        ->orWhere('staging_bytes_charged', '>', 0);
                })
                ->selectRaw('COUNT(*) AS operations, COALESCE(SUM(staging_bytes_charged), 0) AS bytes')
                ->first();
            if ((int) $capacity->operations >= config('photo_processing.max_outstanding_operations')
                || (int) $capacity->bytes + $bytes > config('photo_processing.max_staging_bytes')) {
                throw new PhotoDecoderException(PhotoPostFailure::CapacityReached);
            }

            if ($existing !== null) {
                $existing->update([
                    'attempt_token' => $attemptToken,
                    'status' => PhotoPostStatus::Staging,
                    'caption' => $caption,
                    'location' => $location,
                    'raw_key' => $rawKey,
                    'output_key' => $outputKey,
                    'input_bytes' => $bytes,
                    'staging_bytes_charged' => $bytes,
                    'processed_bytes_charged' => 0,
                    'failure_code' => null,
                    'processing_started_at' => null,
                    'cancel_requested_at' => null,
                    'cleanup_due_at' => null,
                    'cleanup_completed_at' => null,
                ]);

                return $existing->fresh();
            }

            return PhotoPost::query()->create([
                'user_id' => $lockedOwner->id,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'attempt_token' => $attemptToken,
                'status' => PhotoPostStatus::Staging,
                'caption' => $caption,
                'location' => $location,
                'raw_key' => $rawKey,
                'output_key' => $outputKey,
                'input_bytes' => $bytes,
                'staging_bytes_charged' => $bytes,
                'processed_bytes_charged' => 0,
            ]);
        }, attempts: 3);

        if ($post->status !== PhotoPostStatus::Staging || $post->attempt_token !== $attemptToken) {
            return $post;
        }

        $guard = $this->acquireOperationGuard($post->id);
        if ($guard === null) {
            throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
        }

        try {
            $current = PhotoPost::query()->findOrFail($post->id);
            if ($current->attempt_token !== $attemptToken) {
                return $current;
            }
            if ($current->status === PhotoPostStatus::CancelRequested) {
                $this->finishFailure($post->id, $attemptToken, PhotoPostFailure::ProcessingFailed, $guard);

                return $current->fresh();
            }
            if ($current->status !== PhotoPostStatus::Staging) {
                return $current;
            }

            try {
                $input = fopen($path, 'rb');
                if (! is_resource($input) || ! $disk->put($rawKey, $input, ['visibility' => 'private'])) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
                }
                fclose($input);
            } catch (\Throwable) {
                $this->finishFailure($post->id, $attemptToken, PhotoPostFailure::StorageUnavailable, $guard);
                $post->refresh();
                if ($post->status->isFailedOrCancelled()) {
                    return $post;
                }

                throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
            }

            $stored = DB::transaction(function () use ($post, $attemptToken): PhotoPost {
                DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                $current = PhotoPost::query()->lockForUpdate()->findOrFail($post->id);
                if ($current->attempt_token !== $attemptToken || $current->status !== PhotoPostStatus::Staging) {
                    return $current;
                }
                $current->update(['status' => PhotoPostStatus::Queued]);
                ProcessPhotoPost::dispatch($current->id, $attemptToken)
                    ->onConnection('photo_processing')->onQueue('photo-processing');

                return $current->fresh();
            }, attempts: 3);

            if ($stored->attempt_token === $attemptToken && $stored->status === PhotoPostStatus::CancelRequested) {
                $this->finishFailure($stored->id, $attemptToken, PhotoPostFailure::ProcessingFailed, $guard);

                return $stored->fresh();
            }

            return $stored;
        } finally {
            $this->releaseOperationGuard($guard);
        }
    }

    public function cancel(User $owner, PhotoPost $post): PhotoPost
    {
        return DB::transaction(function () use ($owner, $post): PhotoPost {
            DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
            $current = PhotoPost::query()->where('user_id', $owner->id)->lockForUpdate()->findOrFail($post->id);
            if (in_array($current->status, [PhotoPostStatus::Staging, PhotoPostStatus::Queued, PhotoPostStatus::Processing], true)) {
                $current->update([
                    'status' => PhotoPostStatus::CancelRequested,
                    'cancel_requested_at' => now(),
                ]);
            }

            return $current->fresh();
        }, attempts: 3);
    }

    public function delete(User $owner, PhotoPost $post): bool
    {
        $current = DB::transaction(function () use ($owner, $post): PhotoPost {
            DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
            $lockedOwner = User::query()->lockForUpdate()->findOrFail($owner->id);
            $current = PhotoPost::query()->where('user_id', $lockedOwner->id)->lockForUpdate()->findOrFail($post->id);

            if ($current->status === PhotoPostStatus::Deleted) {
                return $current;
            }
            if ($current->status !== PhotoPostStatus::Ready) {
                abort(404);
            }

            $current->update([
                'status' => PhotoPostStatus::Deleted,
                'caption' => null,
                'location' => null,
                'request_fingerprint' => str_repeat('0', 64),
                'deleted_at' => now(),
                'cleanup_due_at' => now(),
                'cleanup_completed_at' => null,
            ]);

            return $current->fresh();
        }, attempts: 3);

        if ($current->cleanup_completed_at !== null) {
            return true;
        }

        return $this->cleanupDeletedPost($current->id, $current->attempt_token);
    }

    private function cleanupDeletedPost(int $postId, string $attemptToken): bool
    {
        $guard = $this->acquireOperationGuard($postId);
        if ($guard === null) {
            return false;
        }

        try {
            $post = PhotoPost::query()->find($postId);
            if ($post === null || $post->attempt_token !== $attemptToken
                || $post->status !== PhotoPostStatus::Deleted) {
                return false;
            }
            if ($post->cleanup_completed_at !== null) {
                return true;
            }

            $clean = $this->deleteArtifacts($post);
            if (! $clean) {
                DB::transaction(function () use ($postId, $attemptToken): void {
                    DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                    $current = PhotoPost::query()->lockForUpdate()->find($postId);
                    if ($current !== null && $current->attempt_token === $attemptToken
                        && $current->status === PhotoPostStatus::Deleted
                        && $current->cleanup_completed_at === null) {
                        $this->reportOverdueDeletion($current);
                        $current->update(['cleanup_due_at' => $this->nextCleanupDue($current)]);
                    }
                }, attempts: 3);

                return false;
            }

            return DB::transaction(function () use ($postId, $attemptToken): bool {
                DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                $current = PhotoPost::query()->lockForUpdate()->find($postId);
                if ($current === null || $current->attempt_token !== $attemptToken
                    || $current->status !== PhotoPostStatus::Deleted) {
                    return false;
                }
                if ($current->cleanup_completed_at !== null) {
                    return true;
                }

                $current->update([
                    'processed_bytes_charged' => 0,
                    'cleanup_due_at' => null,
                    'cleanup_completed_at' => now(),
                ]);

                return true;
            }, attempts: 3);
        } finally {
            $this->releaseOperationGuard($guard);
        }
    }

    private function deleteArtifacts(PhotoPost $post): bool
    {
        $clean = true;
        foreach (['raw_key', 'output_key'] as $field) {
            if (! $this->deleteArtifact($post, $field, $post->{$field})) {
                $clean = false;
            }
        }

        return $clean;
    }

    public function deleteRawInput(PhotoPost $post): bool
    {
        return $this->deleteArtifact($post, 'raw_key', $post->raw_key);
    }

    private function deleteArtifact(PhotoPost $post, string $field, string $key): bool
    {
        if (! $this->isKnownArtifactKey($post, $field, $key)
            || config('filesystems.disks.photo_posts.driver') !== 'local') {
            return false;
        }

        try {
            $disk = Storage::disk('photo_posts');
            $present = $this->artifactPresent($disk, $key);
            if ($present === null) {
                return false;
            }
            if ($present) {
                try {
                    $disk->delete($key);
                } catch (\Throwable) {
                    // The final filesystem proof decides whether the charge can be released.
                }
            }

            return $this->artifactPresent($disk, $key) === false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isKnownArtifactKey(PhotoPost $post, string $field, string $key): bool
    {
        if (! Str::isUuid($post->attempt_token)) {
            return false;
        }

        $token = $post->attempt_token;
        $knownKeys = match ($field) {
            'raw_key' => ["{$token}/original", "photo-posts/{$token}/raw"],
            'output_key' => ["{$token}/processed.webp", "photo-posts/{$token}/processed.webp"],
            default => [],
        };

        return in_array($key, $knownKeys, true);
    }

    private function artifactPresent(mixed $disk, string $key): ?bool
    {
        $root = $disk->path('');
        if (! is_string($root)) {
            return null;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($root === '') {
            $root = DIRECTORY_SEPARATOR;
        }
        if (is_link($root) || ! is_dir($root) || ! is_readable($root) || ! is_executable($root)) {
            return null;
        }
        $root = realpath($root);
        if (! is_string($root)) {
            return null;
        }

        $parent = $root;
        $parts = explode('/', $key);
        foreach ($parts as $index => $part) {
            if (is_link($parent) || ! is_dir($parent) || ! is_readable($parent) || ! is_executable($parent)
                || realpath($parent) !== $parent) {
                return null;
            }
            $entries = @scandir($parent);
            if (! is_array($entries)) {
                return null;
            }
            if (! in_array($part, $entries, true)) {
                return false;
            }

            $path = $parent.DIRECTORY_SEPARATOR.$part;
            $stat = @lstat($path);
            $resolved = realpath($path);
            if (! is_array($stat) || is_link($path) || ! is_string($resolved) || $resolved !== $path) {
                return null;
            }
            $type = $stat['mode'] & 0170000;
            if ($index === array_key_last($parts)) {
                return $type === 0100000 ? true : null;
            }
            if ($type !== 0040000) {
                return null;
            }
            $parent = $path;
        }

        return null;
    }

    private function reportOverdueDeletion(PhotoPost $post): void
    {
        if ($post->deleted_at !== null && $post->cleanup_due_at !== null
            && $post->deleted_at <= now()->subDay()
            && $post->cleanup_due_at <= $post->deleted_at->addDay()) {
            Log::warning('Photo post media cleanup is past the 24-hour deletion deadline.', [
                'photo_post_id' => $post->id,
            ]);
        }
    }

    private function nextCleanupDue(PhotoPost $post): \DateTimeInterface
    {
        $retryAt = now()->addMinute();
        if ($post->status === PhotoPostStatus::Deleted && $post->deleted_at !== null) {
            $deadline = $post->deleted_at->addDay();
            if ($deadline > now() && $retryAt > $deadline) {
                return $deadline;
            }
        }

        return $retryAt;
    }

    /** @param array{resource, string}|null $guard */
    public function finishFailure(int $postId, string $attemptToken, PhotoPostFailure $failure, ?array $guard = null): void
    {
        $ownsGuard = $guard === null;
        $guard ??= $this->acquireOperationGuard($postId);
        if ($guard === null) {
            return;
        }

        try {
            $post = PhotoPost::query()->find($postId);
            if ($post === null || $post->attempt_token !== $attemptToken
                || in_array($post->status, [PhotoPostStatus::Ready, PhotoPostStatus::Deleted], true)) {
                return;
            }

            $clean = $this->deleteArtifacts($post);

            DB::transaction(function () use ($postId, $attemptToken, $failure, $clean): void {
                DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                $post = PhotoPost::query()->lockForUpdate()->find($postId);
                if ($post === null || $post->attempt_token !== $attemptToken
                    || in_array($post->status, [PhotoPostStatus::Ready, PhotoPostStatus::Deleted], true)) {
                    return;
                }
                $cancelled = $post->status === PhotoPostStatus::CancelRequested;
                $post->update([
                    'status' => $cancelled ? PhotoPostStatus::Cancelled : PhotoPostStatus::Failed,
                    'failure_code' => $cancelled ? null : $failure->value,
                    'staging_bytes_charged' => $clean ? 0 : $post->staging_bytes_charged,
                    'processed_bytes_charged' => $clean ? 0 : $post->processed_bytes_charged,
                    'cleanup_due_at' => $clean ? null : now()->addMinute(),
                    'cleanup_completed_at' => $clean ? now() : null,
                ]);
            }, attempts: 3);
        } finally {
            if ($ownsGuard) {
                $this->releaseOperationGuard($guard);
            }
        }
    }

    public function reconcile(): int
    {
        $staleStage = now()->subMinutes(5);
        $staleProcessing = now()->subSeconds(config('photo_processing.processing_recovery_after_seconds'));
        $posts = PhotoPost::query()->where(function ($query) use ($staleStage, $staleProcessing): void {
            $query->where(function ($query): void {
                $query->whereIn('status', ['failed', 'cancelled', 'deleted'])->whereNotNull('cleanup_due_at')->where('cleanup_due_at', '<=', now());
            })->orWhere(function ($query) use ($staleStage): void {
                $query->where(function ($query): void {
                    $query->where('status', PhotoPostStatus::Staging)
                        ->orWhere(function ($query): void {
                            $query->where('status', PhotoPostStatus::CancelRequested)
                                ->whereNull('processing_started_at');
                        });
                })->where('created_at', '<=', $staleStage);
            })->orWhere(function ($query) use ($staleProcessing): void {
                $query->whereIn('status', ['processing', 'cancel_requested'])
                    ->whereNotNull('processing_started_at')
                    ->where('processing_started_at', '<=', $staleProcessing);
            });
        })->orderBy('id')->limit(100)->get();
        $cleaned = 0;

        foreach ($posts as $post) {
            $guard = $this->acquireOperationGuard($post->id);
            if ($guard === null) {
                continue;
            }

            try {
                $current = PhotoPost::query()->find($post->id);
                if ($current === null || $current->attempt_token !== $post->attempt_token
                    || ! $this->needsCleanup($current, $staleStage, $staleProcessing)) {
                    continue;
                }

                $clean = $this->deleteArtifacts($current);
                if (! $clean) {
                    $this->reportOverdueDeletion($current);
                    PhotoPost::query()->whereKey($current->id)->where('attempt_token', $current->attempt_token)
                        ->update(['cleanup_due_at' => $this->nextCleanupDue($current)]);

                    continue;
                }

                DB::transaction(function () use ($current, $staleStage, $staleProcessing, &$cleaned): void {
                    DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                    $post = PhotoPost::query()->lockForUpdate()->find($current->id);
                    if ($post === null || $post->attempt_token !== $current->attempt_token
                        || ! $this->needsCleanup($post, $staleStage, $staleProcessing)) {
                        return;
                    }
                    $completedStatus = match (true) {
                        $post->status === PhotoPostStatus::Staging => PhotoPostStatus::Failed,
                        $post->status === PhotoPostStatus::CancelRequested && $post->processing_started_at === null => PhotoPostStatus::Cancelled,
                        in_array($post->status, [PhotoPostStatus::Processing, PhotoPostStatus::CancelRequested], true) => $post->status === PhotoPostStatus::CancelRequested ? PhotoPostStatus::Cancelled : PhotoPostStatus::Failed,
                        default => $post->status,
                    };
                    $post->update([
                        'status' => $completedStatus,
                        'failure_code' => $completedStatus === PhotoPostStatus::Failed
                            ? ($post->failure_code ?? PhotoPostFailure::StorageUnavailable->value)
                            : $post->failure_code,
                        'staging_bytes_charged' => 0,
                        'processed_bytes_charged' => 0,
                        'cleanup_due_at' => null,
                        'cleanup_completed_at' => now(),
                    ]);
                    $cleaned++;
                }, attempts: 3);
            } finally {
                $this->releaseOperationGuard($guard);
            }
        }

        return $cleaned;
    }

    /** @return array{resource, string}|null */
    public function acquireOperationGuard(int $postId): ?array
    {
        $path = Storage::disk('photo_posts')->path('.locks/'.$postId.'.guard');
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return null;
        }
        if (is_link($directory) || is_link($path)) {
            return null;
        }
        $stream = @fopen($path, 'c');
        if (! is_resource($stream)) {
            return null;
        }
        if (! flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);

            return null;
        }

        return [$stream, $path];
    }

    /** @param array{resource, string} $lock */
    public function releaseOperationGuard(array $lock): void
    {
        flock($lock[0], LOCK_UN);
        fclose($lock[0]);
    }

    private function needsCleanup(PhotoPost $post, \DateTimeInterface $staleStage, \DateTimeInterface $staleProcessing): bool
    {
        return ($post->status->isFailedOrCancelled() && $post->cleanup_completed_at === null
                && $post->cleanup_due_at !== null && $post->cleanup_due_at <= now())
            || ($post->status === PhotoPostStatus::Deleted && $post->cleanup_completed_at === null
                && $post->cleanup_due_at !== null && $post->cleanup_due_at <= now())
            || (in_array($post->status, [PhotoPostStatus::Staging, PhotoPostStatus::CancelRequested], true)
                && $post->processing_started_at === null && $post->created_at !== null && $post->created_at <= $staleStage)
            || (in_array($post->status, [PhotoPostStatus::Processing, PhotoPostStatus::CancelRequested], true)
                && $post->processing_started_at !== null && $post->processing_started_at <= $staleProcessing);
    }
}
