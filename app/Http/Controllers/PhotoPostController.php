<?php

namespace App\Http\Controllers;

use App\Actions\ManagePhotoPosts;
use App\Actions\PhotoLikeState;
use App\Enums\PhotoPostFailure;
use App\Enums\PhotoPostStatus;
use App\Exceptions\PhotoDecoderException;
use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Services\PhotoAccess;
use App\Services\PhotoDecoder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PhotoPostController
{
    public function create(Request $request, PhotoDecoder $decoder): Response
    {
        $response = Inertia::render('photo-posts/create', [
            'decoderAvailable' => $decoder->isAvailable(),
            'limits' => $this->limits(),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(Request $request, ManagePhotoPosts $posts): Response
    {
        $validated = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif'],
            'caption' => ['nullable', 'string', 'max:1000', 'not_regex:/\x00/'],
            'location' => ['nullable', 'string', 'max:'.config('photo_processing.max_location_characters'), 'not_regex:/\x00/'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $photo = $validated['photo'];
        if ($photo->getSize() > config('photo_processing.max_input_bytes')) {
            throw ValidationException::withMessages(['photo' => 'Choose a photo no larger than 20 MB.']);
        }
        try {
            $location = is_string($validated['location'] ?? null)
                ? trim($validated['location'])
                : null;
            $post = $posts->submit($request->user(), $photo, $validated['caption'] ?? null, $validated['idempotency_key'], $location === '' ? null : $location);
        } catch (PhotoDecoderException $exception) {
            throw ValidationException::withMessages(['photo' => $exception->failure->message()]);
        }

        return to_route('photo-posts.show', $post)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, PhotoPost $photoPost, PhotoLikeState $likes, PhotoAccess $access): Response
    {
        $viewer = $request->user();
        $owner = $viewer->is($photoPost->user);
        $isReady = $photoPost->status === PhotoPostStatus::Ready;
        abort_unless($photoPost->status !== PhotoPostStatus::Deleted
            && ($owner || ($isReady && app(PhotoAccess::class)->allows($viewer, $photoPost->user))), 404);

        $canDelete = $owner && $isReady;
        $canCancel = $owner && $photoPost->status->isCancellable();
        $canRetry = $owner && $photoPost->status->isFailedOrCancelled()
            && $photoPost->cleanup_completed_at !== null
            && $photoPost->staging_bytes_charged === 0
            && $photoPost->processed_bytes_charged === 0
            && app(PhotoDecoder::class)->isAvailable()
            && ! PhotoPost::query()->where('user_id', $photoPost->user_id)
                ->where(function ($query): void {
                    $query->whereIn('status', ['staging', 'queued', 'processing', 'cancel_requested'])
                        ->orWhere('staging_bytes_charged', '>', 0);
                })
                ->whereKeyNot($photoPost->id)->exists();
        $failure = $photoPost->failure_code === null ? null : PhotoPostFailure::tryFrom($photoPost->failure_code);
        $detail = null;
        if ($isReady) {
            $likeState = $likes->forViewer($viewer, [$photoPost->id], $access)[$photoPost->id] ?? null;
            abort_if($likeState === null, 404);
            $saved = PhotoSave::query()->where('user_id', $viewer->id)->where('photo_post_id', $photoPost->id)->first();
            $detail = [
                'author' => ['id' => $photoPost->user->id, 'name' => $photoPost->user->name],
                'createdAt' => $photoPost->created_at?->toISOString(),
                'location' => $photoPost->location,
                'likeState' => $likeState,
                'saveState' => ['saved' => (bool) ($saved->is_saved ?? false), 'revision' => (int) ($saved->revision ?? 0)],
            ];
        }

        $response = Inertia::render('photo-posts/show', [
            'photoPost' => [
                'id' => $photoPost->id,
                'status' => $photoPost->status->value,
                'caption' => $photoPost->caption,
                'location' => $photoPost->location,
                'mediaUrl' => $isReady ? route('photo-posts.media', $photoPost) : null,
                'canDelete' => $canDelete,
                'canCancel' => $canCancel,
                'canRetry' => $canRetry,
                'retryKey' => $canRetry ? $photoPost->idempotency_key : null,
                'failure' => $failure === null ? null : ['code' => $failure->value, 'message' => $failure->message()],
            ],
            'detail' => $detail,
            'limits' => $this->limits(),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function media(Request $request, PhotoPost $photoPost): BinaryFileResponse
    {
        abort_unless($photoPost->status === PhotoPostStatus::Ready
            && app(PhotoAccess::class)->allows($request->user(), $photoPost->user), 404);

        $disk = Storage::disk('photo_posts');
        $path = $disk->path($photoPost->output_key);
        $root = realpath($disk->path(''));
        $realPath = realpath($path);
        abort_unless(is_string($root) && is_string($realPath)
            && str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)
            && is_file($realPath) && ! is_link($path), 404);

        $response = response()->file($realPath, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="photo.webp"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function cancel(Request $request, PhotoPost $photoPost, ManagePhotoPosts $posts): Response
    {
        abort_unless($request->user()->is($photoPost->user)
            && $photoPost->status !== PhotoPostStatus::Deleted, 404);
        $posts->cancel($request->user(), $photoPost);

        return to_route('photo-posts.show', $photoPost)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, PhotoPost $photoPost, ManagePhotoPosts $posts): RedirectResponse
    {
        abort_unless($request->user()->is($photoPost->user)
            && in_array($photoPost->status, [PhotoPostStatus::Ready, PhotoPostStatus::Deleted], true), 404);

        $cleaned = $posts->delete($request->user(), $photoPost);
        Inertia::flash('toast', [
            'type' => $cleaned ? 'success' : 'warning',
            'message' => $cleaned
                ? 'Your photo post was deleted.'
                : 'Your photo post was removed. Photo cleanup will retry.',
        ]);

        return to_route('dashboard');
    }

    /** @return array{maxInputBytes: int, maxPixels: int, maxCaptionCharacters: int, maxLocationCharacters: int, accept: list<string>} */
    private function limits(): array
    {
        return [
            'maxInputBytes' => config('photo_processing.max_input_bytes'),
            'maxPixels' => config('photo_processing.max_input_pixels'),
            'maxCaptionCharacters' => config('photo_processing.max_caption_characters'),
            'maxLocationCharacters' => config('photo_processing.max_location_characters'),
            'accept' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
        ];
    }
}
