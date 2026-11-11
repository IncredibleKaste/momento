<?php

namespace App\Http\Controllers;

use App\Actions\PhotoLikeState;
use App\Http\Requests\PeopleSearchRequest;
use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Models\User;
use App\Services\PhotoAccess;
use DateTimeImmutable;
use Illuminate\Pagination\Cursor;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PeopleSearchController extends Controller
{
    public function __invoke(PeopleSearchRequest $request, PhotoAccess $photoAccess, PhotoLikeState $likes): Response
    {
        $query = $request->validated('q');
        $people = null;
        $photos = null;

        if (is_string($query)) {
            $cursor = $this->photoCursor($request->query('photosCursor'));
            if ($cursor === false) {
                return to_route('people.index', ['q' => $query])->withHeaders(['Cache-Control' => 'private, no-store']);
            }

            /** @var User $viewer */
            $viewer = $request->user();
            $literalQuery = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);

            // shortcut: Unicode case folding follows the database; add a normalized name key only if cross-database consistency becomes required.
            $people = User::query()
                ->select(['id', 'name'])
                ->where('id', '<>', $viewer->id)
                ->whereNotNull('email_verified_at')
                ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", ['%'.$literalQuery.'%'])
                ->orderBy('name')
                ->orderBy('id')
                ->simplePaginate(12)
                ->appends(['q' => $query, 'photosCursor' => $cursor?->encode()])
                ->through(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                ]);

            $photoPage = $photoAccess->visiblePosts($viewer)
                ->with('user:id,name')
                ->whereRaw("LOWER(photo_posts.caption) LIKE LOWER(?) ESCAPE '!'", ['%'.$literalQuery.'%'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(12, ['photo_posts.id', 'photo_posts.user_id', 'photo_posts.caption', 'photo_posts.location', 'photo_posts.created_at'], 'photosCursor', $cursor)
                ->appends(['q' => $query, 'page' => $people->currentPage()]);

            $pageIds = array_values($photoPage->getCollection()
                ->map(fn (PhotoPost $post): int => $post->id)->all());
            $likeStates = $likes->forViewer($viewer, $pageIds, $photoAccess);
            $saveStates = PhotoSave::query()->where('user_id', $viewer->id)
                ->whereIn('photo_post_id', $pageIds)
                ->get(['photo_post_id', 'is_saved', 'revision'])->keyBy('photo_post_id');

            $photos = [
                'data' => $photoPage->getCollection()->map(fn (PhotoPost $post): array => [
                    'id' => $post->id,
                    'caption' => $post->caption,
                    'location' => $post->location,
                    'createdAt' => $post->created_at->toIso8601String(),
                    'author' => ['id' => $post->user->id, 'name' => $post->user->name],
                    'mediaUrl' => route('photo-posts.media', $post),
                    'detailUrl' => route('photo-posts.show', $post),
                    'likeState' => $likeStates[$post->id] ?? ['liked' => false, 'revision' => 0],
                    'saveState' => [
                        'saved' => (bool) ($saveStates->get($post->id)->is_saved ?? false),
                        'revision' => (int) ($saveStates->get($post->id)->revision ?? 0),
                    ],
                ])->values(),
                'prev_page_url' => $photoPage->previousPageUrl(),
                'next_page_url' => $photoPage->nextPageUrl(),
            ];
        }

        $response = Inertia::render('people/index', [
            'q' => $query,
            'people' => $people,
            'photos' => $photos,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function photoCursor(mixed $encodedCursor): Cursor|false|null
    {
        if ($encodedCursor === null) {
            return null;
        }

        if (! is_string($encodedCursor) || strlen($encodedCursor) > 512) {
            return false;
        }

        $cursor = Cursor::fromEncoded($encodedCursor);
        $parameters = $cursor?->toArray() ?? [];
        $timestamp = $parameters['created_at'] ?? null;
        $id = $parameters['id'] ?? null;
        $date = is_string($timestamp) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $timestamp) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp) : false;
        $validId = is_int($id) ? $id > 0 : is_string($id)
            && preg_match('/^[1-9][0-9]*$/D', $id) === 1
            && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;

        if (count($parameters) !== 3 || ! is_bool($parameters['_pointsToNextItems'] ?? null)
            || ! $date || $date->format('Y-m-d H:i:s') !== $timestamp || $date->format('Y') === '0000' || ! $validId) {
            return false;
        }

        return $cursor;
    }
}
