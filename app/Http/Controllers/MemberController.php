<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MemberController extends Controller
{
    public function show(Request $request, User $member): Response
    {
        abort_unless($member->hasVerifiedEmail(), 404);
        $follow = Follow::query()->where('follower_id', $request->user()->id)
            ->where('followed_id', $member->id)->first();

        $response = Inertia::render('members/show', [
            'member' => ['id' => $member->id, 'name' => $member->name],
            'follow' => $follow ? ['id' => $follow->id, 'status' => $follow->status] : null,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
