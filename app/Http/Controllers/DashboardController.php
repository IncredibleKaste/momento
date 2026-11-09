<?php

namespace App\Http\Controllers;

use App\Models\PhotoPost;
use App\Services\PhotoAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PhotoAccess $photoAccess): Response
    {
        $viewer = $request->user();

        $followedPeople = DB::table('follows')
            ->join('users as followed_users', 'follows.followed_id', '=', 'followed_users.id')
            ->where('follows.follower_id', $viewer->id)
            ->where('follows.status', 'approved')
            ->whereNotNull('followed_users.email_verified_at')
            ->orderByDesc('follows.id')
            ->limit(12)
            ->get(['followed_users.id as id', 'followed_users.name as name'])
            ->map(fn (object $person): array => [
                'id' => $person->id,
                'name' => $person->name,
            ])
            ->values();

        $photoPosts = $photoAccess->visiblePosts($viewer)
            ->with('user:id,name')
            ->orderByDesc('photo_posts.created_at')
            ->orderByDesc('photo_posts.id')
            ->cursorPaginate(12, [
                'photo_posts.id',
                'photo_posts.user_id',
                'photo_posts.caption',
                'photo_posts.created_at',
            ], 'postsCursor');

        $posts = $photoPosts->getCollection()->map(fn (PhotoPost $post): array => [
            'id' => $post->id,
            'caption' => $post->caption,
            'createdAt' => $post->created_at->toIso8601String(),
            'author' => [
                'id' => $post->user->id,
                'name' => $post->user->name,
            ],
            'mediaUrl' => route('photo-posts.media', $post),
            'detailUrl' => route('photo-posts.show', $post),
        ])->values();

        $response = Inertia::render('dashboard', [
            'followedPeople' => $followedPeople,
            'photoPosts' => [
                'data' => $posts,
                'nextCursor' => $photoPosts->nextCursor()?->encode(),
                'previousCursor' => $photoPosts->previousCursor()?->encode(),
            ],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
