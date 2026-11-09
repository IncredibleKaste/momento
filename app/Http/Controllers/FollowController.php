<?php

namespace App\Http\Controllers;

use App\Actions\ManageFollows;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class FollowController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        $response = Inertia::render('following/index', [
            'incoming' => $this->relationships($viewer, true, 'pending', 'incomingPage'),
            'outgoing' => $this->relationships($viewer, false, 'pending', 'outgoingPage'),
            'followers' => $this->relationships($viewer, true, 'approved', 'followersPage'),
            'following' => $this->relationships($viewer, false, 'approved', 'followingPage'),
            'status' => $request->session()->get('status'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
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
        $memberKey = $incoming ? 'follows.follower_id' : 'follows.followed_id';
        $ownerKey = $incoming ? 'follows.followed_id' : 'follows.follower_id';

        return DB::table('follows')
            ->join('users as members', 'members.id', '=', $memberKey)
            ->where($ownerKey, $viewer->id)
            ->where('follows.status', $status)
            ->orderByDesc('follows.id')
            ->paginate(12, [
                'follows.id as relationship_id',
                'follows.status as relationship_status',
                'members.id as member_id',
                'members.name as member_name',
            ], $page)
            ->withQueryString()
            ->through(function (stdClass $relationship): array {
                return [
                    'id' => (int) $relationship->relationship_id,
                    'status' => (string) $relationship->relationship_status,
                    'member' => [
                        'id' => (int) $relationship->member_id,
                        'name' => (string) $relationship->member_name,
                    ],
                ];
            });
    }
}
