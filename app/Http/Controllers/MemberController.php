<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MemberController extends Controller
{
    public function show(Request $request, User $member): Response
    {
        abort_unless($member->hasVerifiedEmail(), 404);
        $follow = Follow::query()->where('follower_id', $request->user()->id)
            ->where('followed_id', $member->id)->first();
        $canMessage = ! $request->user()->is($member) && DB::table('follows')->where('status', 'approved')->where(function ($query) use ($request, $member): void {
            $query->where(fn ($query) => $query->where('follower_id', $request->user()->id)->where('followed_id', $member->id))
                ->orWhere(fn ($query) => $query->where('follower_id', $member->id)->where('followed_id', $request->user()->id));
        })->count() === 2;

        $response = Inertia::render('members/show', [
            'member' => ['id' => $member->id, 'name' => $member->name],
            'follow' => $follow ? ['id' => $follow->id, 'status' => $follow->status] : null,
            'canMessage' => $canMessage,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
