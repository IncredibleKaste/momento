<?php

namespace App\Jobs;

use App\Actions\ManagePhotoPosts;
use App\Enums\PhotoPostFailure;
use App\Enums\PhotoPostStatus;
use App\Exceptions\PhotoDecoderException;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoDecoder;
use App\Services\WebpOutputInspector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPhotoPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 140;

    public int $tries = 0;

    public function __construct(public readonly int $photoPostId, public readonly string $attemptToken) {}

    public function handle(PhotoDecoder $decoder, WebpOutputInspector $inspector, ManagePhotoPosts $posts): void
    {
        $guard = $posts->acquireOperationGuard($this->photoPostId);
        if ($guard === null) {
            $this->release(10);

            return;
        }

        try {
            $this->processUnderGuard($decoder, $inspector, $posts, $guard);
        } finally {
            $posts->releaseOperationGuard($guard);
        }
    }

    /** @param array{resource, string} $guard */
    private function processUnderGuard(PhotoDecoder $decoder, WebpOutputInspector $inspector, ManagePhotoPosts $posts, array $guard): void
    {
        $post = PhotoPost::query()->find($this->photoPostId);
        if ($post === null || in_array($post->status, [PhotoPostStatus::Ready, PhotoPostStatus::Deleted], true)
            || $post->status->isFailedOrCancelled()) {
            return;
        }

        $attemptToken = $this->claim($post);
        if ($attemptToken === null) {
            return;
        }
        $post = PhotoPost::query()->findOrFail($this->photoPostId);

        if ($post->status === PhotoPostStatus::CancelRequested) {
            $posts->finishFailure($post->id, $attemptToken, PhotoPostFailure::ProcessingFailed, $guard);

            return;
        }

        try {
            if (! $decoder->isAvailable()) {
                throw new PhotoDecoderException(PhotoPostFailure::DecoderUnavailable);
            }

            $disk = Storage::disk('photo_posts');
            $inputPath = $disk->path($post->raw_key);
            $outputPath = $disk->path($post->output_key);
            if (! is_file($inputPath) || is_link($inputPath)) {
                throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
            }

            if (! is_file($outputPath)) {
                $directory = dirname($outputPath);
                if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
                }
                if (is_link($directory)) {
                    throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
                }
                $decoder->decode($inputPath, $outputPath);
            }
            $output = $inspector->inspect($outputPath);

            if (! $posts->deleteRawInput($post)) {
                throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
            }

            $ready = DB::transaction(function () use ($post, $attemptToken, $output): bool {
                DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
                $owner = User::query()->lockForUpdate()->findOrFail($post->user_id);
                $current = PhotoPost::query()->lockForUpdate()->findOrFail($post->id);
                if ($current->attempt_token !== $attemptToken || $current->status !== PhotoPostStatus::Processing) {
                    return false;
                }
                $used = (int) PhotoPost::query()->where('user_id', $owner->id)
                    ->sum('processed_bytes_charged');
                if ($used + $output['bytes'] > config('photo_processing.max_processed_bytes_per_user')) {
                    return false;
                }

                $current->update([
                    'status' => PhotoPostStatus::Ready,
                    'staging_bytes_charged' => 0,
                    'processed_bytes_charged' => $output['bytes'],
                    'failure_code' => null,
                    'cleanup_due_at' => null,
                    'cleanup_completed_at' => now(),
                ]);

                return true;
            }, attempts: 3);

            if (! $ready) {
                $posts->finishFailure($post->id, $attemptToken, PhotoPostFailure::CapacityReached, $guard);
            }
        } catch (PhotoDecoderException $exception) {
            $posts->finishFailure($post->id, $attemptToken, $exception->failure, $guard);
        } catch (Throwable) {
            $posts->finishFailure($post->id, $attemptToken, PhotoPostFailure::ProcessingFailed, $guard);
        }
    }

    private function claim(PhotoPost $post): ?string
    {
        $rawToken = $this->attemptToken;
        $claimed = DB::transaction(function () use ($post, $rawToken): bool {
            DB::table('photo_upload_capacity')->where('id', 1)->lockForUpdate()->first();
            $current = PhotoPost::query()->lockForUpdate()->find($post->id);
            if ($current === null || $current->status === PhotoPostStatus::Ready || $current->status->isFailedOrCancelled()) {
                return false;
            }

            if ($current->attempt_token !== $rawToken) {
                return false;
            }

            if ($current->status === PhotoPostStatus::Staging) {
                return false;
            }

            if ($current->status === PhotoPostStatus::Processing) {
                if ($this->job?->attempts() < 2) {
                    return false;
                }
                if ($this->anotherDecoderIsActive($current->id)) {
                    $this->release(10);

                    return false;
                }

                return true;
            }

            if ($this->anotherDecoderIsActive($current->id)) {
                $this->release(10);

                return false;
            }

            if ($current->status === PhotoPostStatus::CancelRequested) {
                if ($current->processing_started_at !== null && $this->job?->attempts() < 2) {
                    return false;
                }

                return true;
            }

            if ($current->status !== PhotoPostStatus::Queued) {
                return false;
            }
            $current->update([
                'status' => PhotoPostStatus::Processing,
                'processing_started_at' => now(),
            ]);

            return true;
        }, attempts: 3);

        return $claimed ? $rawToken : null;
    }

    private function anotherDecoderIsActive(int $postId): bool
    {
        return PhotoPost::query()->whereKeyNot($postId)->where(function ($query): void {
            $query->where('status', PhotoPostStatus::Processing)
                ->orWhere(function ($query): void {
                    $query->where('status', PhotoPostStatus::CancelRequested)
                        ->whereNotNull('processing_started_at');
                });
        })->exists();
    }
}
