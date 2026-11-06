<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
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

        $response = Inertia::render('dashboard', [
            'followedPeople' => $followedPeople,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
