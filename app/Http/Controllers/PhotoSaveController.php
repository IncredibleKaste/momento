<?php

namespace App\Http\Controllers;

use App\Actions\ManagePhotoSaves;
use App\Http\Requests\SetPhotoSaveRequest;
use App\Models\PhotoPost;
use App\Services\PhotoAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class PhotoSaveController extends Controller
{
    public function index(Request $request, PhotoAccess $access): Response
    {
        $viewer = $request->user();
        $savedPosts = $access->visiblePosts($viewer)
            ->join('photo_saves', function ($join) use ($viewer): void {
                $join->on('photo_saves.photo_post_id', '=', 'photo_posts.id')
                    ->where('photo_saves.user_id', $viewer->id)
                    ->where('photo_saves.is_saved', true);
            })
            ->join('users as authors', 'authors.id', '=', 'photo_posts.user_id')
            ->toBase()
            ->orderByDesc('photo_saves.saved_order')
            ->orderByDesc('photo_posts.id')
            ->paginate(12, [
                'photo_posts.id as id',
                'photo_posts.caption as caption',
                'authors.id as author_id',
                'authors.name as author_name',
                'photo_saves.revision as revision',
            ])
            ->through(static fn (stdClass $post): array => [
                'id' => (int) $post->id,
                'caption' => $post->caption,
                'author' => ['id' => (int) $post->author_id, 'name' => (string) $post->author_name],
                'revision' => (int) $post->revision,
            ]);

        $response = Inertia::render('saved/index', [
            'savedPosts' => $savedPosts,
            'error' => $request->session()->get('errors')?->getBag('default')->first('save'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function update(SetPhotoSaveRequest $request, PhotoPost $photoPost, ManagePhotoSaves $saves): RedirectResponse
    {
        $input = $request->validated();
        $saves->set(
            $request->user(),
            $photoPost,
            filter_var($input['saved'], FILTER_VALIDATE_BOOLEAN),
            (int) $input['revision'],
            $input['request_id'],
        );

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $this->isSameOrigin($referer, $request)) {
            return redirect()->to($referer)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return to_route('saved.index')->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function isSameOrigin(string $url, Request $request): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $origin = is_string($scheme) && is_string($host)
            ? strtolower($scheme.'://'.$host.($port === null ? '' : ':'.$port))
            : '';

        return $origin !== '' && $origin === strtolower($request->getSchemeAndHttpHost());
    }
}
