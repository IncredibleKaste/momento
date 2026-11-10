<?php

namespace App\Http\Controllers;

use App\Actions\PhotoLikeState;
use App\Models\PhotoPost;
use App\Services\PhotoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoLikeController extends Controller
{
    public function store(Request $request, PhotoPost $photoPost, PhotoAccess $access, PhotoLikeState $likeState): JsonResponse
    {
        $actor = $request->user();
        abort_unless($access->visiblePosts($actor)->whereKey($photoPost->getKey())->exists(), 404);

        $validated = $request->validate([
            'liked' => ['required', 'boolean'],
            'revision' => ['required', 'integer', 'min:0'],
            'request_id' => ['required', 'uuid'],
        ]);

        $state = $likeState->handle(
            $actor,
            $photoPost,
            (bool) $validated['liked'],
            (int) $validated['revision'],
            $validated['request_id'],
            $access,
        );

        return response()->json(
            ['liked' => $state['liked'], 'revision' => $state['revision']],
            $state['conflict'] ? 409 : 200,
        )->header('Cache-Control', 'private, no-store');
    }
}
