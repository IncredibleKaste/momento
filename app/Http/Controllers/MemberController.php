<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class MemberController extends Controller
{
    public function show(Request $request, User $member, PhotoAccess $access): Response
    {
        abort_unless($member->hasVerifiedEmail(), 404);
        $viewer = $request->user();
        $follow = Follow::query()->where('follower_id', $request->user()->id)
            ->where('followed_id', $member->id)->first();
        $canMessage = ! $viewer->is($member) && DB::table('follows')->where('status', 'approved')->where(function ($query) use ($viewer, $member): void {
            $query->where(fn ($query) => $query->where('follower_id', $viewer->id)->where('followed_id', $member->id))
                ->orWhere(fn ($query) => $query->where('follower_id', $member->id)->where('followed_id', $viewer->id));
        })->count() === 2;

        $props = [
            'member' => ['id' => $member->id, 'name' => $member->name],
            'follow' => $follow ? ['id' => $follow->id, 'status' => $follow->status] : null,
            'canMessage' => $canMessage,
        ];

        if ($access->allows($viewer, $member)) {
            $cursor = $this->profileCursor($request, $member);
            if ($cursor instanceof Response) {
                return $cursor;
            }

            $photoPage = $access->visiblePosts($viewer)
                ->where('photo_posts.user_id', $member->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(12, [
                    'photo_posts.id',
                    'photo_posts.caption',
                    'photo_posts.created_at',
                ], 'cursor', $cursor);

            $props['photos'] = [
                'data' => collect($photoPage->items())->map(static fn (PhotoPost $post): array => [
                    'id' => (int) $post->id,
                    'caption' => $post->caption,
                    'createdAt' => $post->created_at?->toIso8601String(),
                    'mediaUrl' => route('photo-posts.media', $post->id),
                    'detailUrl' => route('photo-posts.show', $post->id),
                ])->all(),
                'prev_page_url' => $photoPage->previousPageUrl(),
                'next_page_url' => $photoPage->nextPageUrl(),
            ];
        }

        $response = Inertia::render('members/show', $props)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function profileCursor(Request $request, User $member): Cursor|Response|null
    {
        $encodedCursor = $request->query('cursor');
        if ($encodedCursor === null) {
            return null;
        }

        $cursor = Cursor::fromEncoded($encodedCursor);
        if ($cursor === null) {
            return to_route('members.show', $member)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        try {
            $parameters = $cursor->parameters(['created_at', 'id']);
        } catch (UnexpectedValueException) {
            return to_route('members.show', $member)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        $cursorId = $parameters[1];
        $validCursorId = is_int($cursorId)
            ? $cursorId > 0
            : is_string($cursorId)
                && preg_match('/^[1-9][0-9]*$/D', $cursorId) === 1
                && filter_var($cursorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;

        if (! is_string($parameters[0]) || strtotime($parameters[0]) === false || ! $validCursorId) {
            return to_route('members.show', $member)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return $cursor;
    }
}
