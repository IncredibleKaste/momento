<?php

namespace App\Http\Controllers;

use App\Http\Requests\PeopleSearchRequest;
use App\Models\User;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PeopleSearchController extends Controller
{
    public function __invoke(PeopleSearchRequest $request): Response
    {
        $query = $request->validated('q');
        $people = null;

        if (is_string($query)) {
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
                ->appends(['q' => $query])
                ->through(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                ]);
        }

        $response = Inertia::render('people/index', [
            'q' => $query,
            'people' => $people,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
