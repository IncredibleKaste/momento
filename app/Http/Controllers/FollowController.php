<?php

namespace App\Http\Controllers;

use App\Actions\ManageFollows;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class FollowController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        return Inertia::render('following/index', [
            'incoming' => $this->relationships($viewer, true, 'pending', 'incomingPage'),
            'outgoing' => $this->relationships($viewer, false, 'pending', 'outgoingPage'),
            'followers' => $this->relationships($viewer, true, 'approved', 'followersPage'),
            'following' => $this->relationships($viewer, false, 'approved', 'followingPage'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, User $member, ManageFollows $follows): RedirectResponse
    {
        $follows->request($request->user(), $member);

        return to_route('following.index')->with('status', 'Your follow request is recorded.');
    }

    public function approve(Request $request, Follow $follow, ManageFollows $follows): RedirectResponse
    {
        $follows->approve($request->user(), $follow);

        return to_route('following.index')->with('status', 'Follow request approved.');
    }

    public function destroy(Request $request, Follow $follow, ManageFollows $follows): RedirectResponse
    {
        $follows->remove($request->user(), $follow);

        return to_route('following.index')->with('status', 'Follow removed. New photo access is no longer allowed.');
    }

    /** @return LengthAwarePaginator<int, array{id: int, status: string, member: array{id: int, name: string}}> */
    private function relationships(User $viewer, bool $incoming, string $status, string $page): LengthAwarePaginator
    {
        return Follow::query()
            ->where($incoming ? 'followed_id' : 'follower_id', $viewer->id)
            ->where('status', $status)
            ->with($incoming ? 'follower:id,name' : 'followed:id,name')
            ->orderByDesc('id')
            ->paginate(12, ['id', 'follower_id', 'followed_id', 'status'], $page)
            ->withQueryString()
            ->through(function (Follow $follow) use ($incoming): array {
                $member = $incoming ? $follow->follower : $follow->followed;

                return ['id' => $follow->id, 'status' => $follow->status, 'member' => ['id' => $member->id, 'name' => $member->name]];
            });
    }
}
