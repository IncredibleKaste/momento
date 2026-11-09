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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ManagePhotoPosts
{
    public function submit(User $owner, UploadedFile $photo, ?string $caption, string $idempotencyKey): PhotoPost
    {
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
        $fingerprint = hash('sha256', $contentHash."\0".($caption ?? ''));

        $attemptToken = (string) Str::uuid();
        $disk = Storage::disk('photo_posts');
        $rawKey = $attemptToken.'/original';
        $outputKey = $attemptToken.'/processed.webp';

        $post = DB::transaction(function () use ($owner, $idempotencyKey, $fingerprint, $attemptToken, $rawKey, $outputKey, $bytes, $caption): PhotoPost {
            DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
            $lockedOwner = User::query()->lockForUpdate()->findOrFail($owner->id);
            $existing = PhotoPost::query()->where('user_id', $lockedOwner->id)
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new HttpException(409, 'This retry key belongs to different photo or caption content.');
                }
                if (! $existing->status->isFailedOrCancelled()) {
                    return $existing;
                }
                if ($existing->cleanup_completed_at === null || $existing->staging_bytes_charged !== 0
                    || $existing->processed_bytes_charged !== 0) {
                    throw ValidationException::withMessages([
                        'photo' => 'Cleanup is still pending. Retry shortly with the same photo and caption.',
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
            if ($post === null || $post->attempt_token !== $attemptToken || $post->status === PhotoPostStatus::Ready) {
                return;
            }

            $disk = Storage::disk('photo_posts');
            $clean = true;
            foreach ([$post->raw_key, $post->output_key] as $key) {
                try {
                    if ($disk->exists($key) && ! $disk->delete($key)) {
                        $clean = false;
                    }
                    if ($disk->exists($key)) {
                        $clean = false;
                    }
                } catch (\Throwable) {
                    $clean = false;
                }
            }

            DB::transaction(function () use ($postId, $attemptToken, $failure, $clean): void {
                DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                $post = PhotoPost::query()->lockForUpdate()->find($postId);
                if ($post === null || $post->attempt_token !== $attemptToken || $post->status === PhotoPostStatus::Ready) {
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
                $query->whereIn('status', ['failed', 'cancelled'])->whereNotNull('cleanup_due_at')->where('cleanup_due_at', '<=', now());
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
        $disk = Storage::disk('photo_posts');

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

                $clean = true;
                foreach ([$current->raw_key, $current->output_key] as $key) {
                    try {
                        if ($disk->exists($key) && ! $disk->delete($key)) {
                            $clean = false;
                        }
                        if ($disk->exists($key)) {
                            $clean = false;
                        }
                    } catch (\Throwable) {
                        $clean = false;
                    }
                }
                if (! $clean) {
                    PhotoPost::query()->whereKey($current->id)->where('attempt_token', $current->attempt_token)
                        ->update(['cleanup_due_at' => now()->addMinute()]);

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
            || (in_array($post->status, [PhotoPostStatus::Staging, PhotoPostStatus::CancelRequested], true)
                && $post->processing_started_at === null && $post->created_at !== null && $post->created_at <= $staleStage)
            || (in_array($post->status, [PhotoPostStatus::Processing, PhotoPostStatus::CancelRequested], true)
                && $post->processing_started_at !== null && $post->processing_started_at <= $staleProcessing);
    }
}
