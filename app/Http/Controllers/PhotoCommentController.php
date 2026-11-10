<?php

namespace App\Http\Controllers;

use App\Actions\ManagePhotoComments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PhotoCommentController extends Controller
{
    public function index(Request $request, int $photoPost, ManagePhotoComments $comments): JsonResponse
    {
        $comments->assertVisible($request->user(), $photoPost);
        $validated = $request->validate(['after' => ['sometimes', 'integer', 'min:1']]);
        $payload = $comments->page($request->user(), $photoPost, isset($validated['after']) ? (int) $validated['after'] : null);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $photoPost, ManagePhotoComments $comments): JsonResponse
    {
        $comments->assertVisible($request->user(), $photoPost);
        $content = $request->getContent();
        $payload = json_decode($content, true);
        $validated = Validator::make(is_array($payload) ? $payload : [], [
            'body' => [
                'required', 'string', 'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && str_contains($value, "\0")) {
                        $fail('Comments cannot contain null characters.');
                    }

                    if (is_string($value) && preg_match('/\A[\p{Z}\x{0009}-\x{000D}\x{0085}]*\z/u', $value) === 1) {
                        $fail('Write a comment before posting.');
                    }
                },
            ],
            'submissionKey' => ['required', 'uuid'],
        ])->validate();

        $result = $comments->add($request->user(), $photoPost, $validated['body'], Str::lower($validated['submissionKey']));
        $comment = $result['comment'];

        return response()->json([
            'comment' => [
                'id' => $comment->id,
                'author' => ['id' => $comment->author->id, 'name' => $comment->author->name],
                'body' => $comment->body,
                'createdAt' => $comment->created_at?->toISOString(),
                'canRemove' => $comment->author_id === $request->user()->id || $result['postOwnerId'] === $request->user()->id,
            ],
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $comment, ManagePhotoComments $comments): Response
    {
        $comments->remove($request->user(), $comment);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
