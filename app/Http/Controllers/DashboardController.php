<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        $followedPeople = Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('status', 'approved')
            ->whereHas('followed', fn (Builder $query) => $query->whereNotNull('email_verified_at'))
            ->with('followed:id,name')
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'followed_id'])
            ->map(fn (Follow $follow): array => [
                'id' => $follow->followed->id,
                'name' => $follow->followed->name,
            ])
            ->values();

        $response = Inertia::render('dashboard', [
            'followedPeople' => $followedPeople,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
