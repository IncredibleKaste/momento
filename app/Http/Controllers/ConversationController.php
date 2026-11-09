<?php

namespace App\Http\Controllers;

use App\Actions\ManageConversation;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $search = $request->string('q')->trim()->limit(80)->toString();

        return $this->privatePage($request, 'messages/index', [
            'conversations' => $this->rail($viewer, $search),
            'search' => $search,
            'thread' => null,
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $viewer = $request->user();
        $participant = app(ManageConversation::class)->participant($conversation, $viewer);
        $before = $request->validate(['before' => ['sometimes', 'integer', 'min:1']])['before'] ?? null;
        $query = $conversation->messages()->with('sender:id,name')->orderByDesc('id');
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $page = $query->limit(51)->get();
        $hasOlder = $page->count() > 50;
        $messages = $page->take(50)->reverse()->values();
        $unreadCount = $conversation->messages()->where('sender_id', '!=', $viewer->id)
            ->where('id', '>', $participant->last_read_message_id ?? 0)->count();
        $conversation->load(['memberOne:id,name,email_verified_at', 'memberTwo:id,name,email_verified_at']);
        $member = $conversation->member_one_id === $viewer->id ? $conversation->memberTwo : $conversation->memberOne;
        $canSend = $viewer->hasVerifiedEmail()
            && $conversation->memberOne->hasVerifiedEmail()
            && $conversation->memberTwo->hasVerifiedEmail()
            && DB::table('follows')->where('status', 'approved')->where(function ($query) use ($conversation): void {
                $query->where(fn ($query) => $query->where('follower_id', $conversation->member_one_id)->where('followed_id', $conversation->member_two_id))
                    ->orWhere(fn ($query) => $query->where('follower_id', $conversation->member_two_id)->where('followed_id', $conversation->member_one_id));
            })->count() === 2;

        return $this->privatePage($request, 'messages/index', [
            'conversations' => $this->rail($viewer, $request->string('q')->trim()->limit(80)->toString()),
            'search' => $request->string('q')->trim()->limit(80)->toString(),
            'thread' => [
                'id' => $conversation->id,
                'member' => ['id' => $member->id, 'name' => $member->name],
                'messages' => $messages->map(fn (ConversationMessage $message): array => [
                    'id' => $message->id,
                    'senderId' => $message->sender_id,
                    'senderName' => $message->sender->name,
                    'body' => $message->body,
                    'createdAt' => $message->created_at?->toISOString(),
                ])->all(),
                'unreadCount' => $unreadCount,
                'lastReadMessageId' => $participant->last_read_message_id,
                'nextBefore' => $hasOlder ? $messages->first()?->id : null,
                'isLatestPage' => $before === null,
                'canSend' => $canSend,
            ],
        ]);
    }

    public function store(Request $request, User $member, ManageConversation $conversations): RedirectResponse
    {
        $conversation = $conversations->start($request->user(), $member);

        return to_route('messages.show', $conversation);
    }

    public function send(Request $request, Conversation $conversation, ManageConversation $conversations): RedirectResponse
    {
        $conversations->participant($conversation, $request->user());
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'nonce' => ['required', 'uuid'],
        ]);
        $conversations->send($request->user(), $conversation->id, $validated['body'], $validated['nonce']);

        return to_route('messages.show', $conversation);
    }

    public function read(Request $request, Conversation $conversation, ConversationMessage $message, ManageConversation $conversations): Response
    {
        $conversations->markRead($request->user(), $conversation->id, $message->id);

        return response()->noContent();
    }

    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     member: array{id: int, name: string},
     *     latestMessage: array{body: string, createdAt: string|null}|null,
     *     unreadCount: int
     * }>
     */
    private function rail(User $viewer, string $search): LengthAwarePaginator
    {
        return ConversationParticipant::query()->where('user_id', $viewer->id)
            ->select('conversation_participants.*')
            ->selectSub(ConversationMessage::query()->selectRaw('count(*)')
                ->whereColumn('conversation_id', 'conversation_participants.conversation_id')
                ->where('sender_id', '!=', $viewer->id)
                ->whereRaw('conversation_messages.id > COALESCE(conversation_participants.last_read_message_id, 0)'), 'unread_count')
            ->when($search !== '', function ($query) use ($search, $viewer): void {
                $literalSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
                $query->whereHas('conversation', function ($query) use ($literalSearch, $viewer): void {
                    $nameMatch = fn ($query) => $query->where('id', '!=', $viewer->id)
                        ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", ['%'.$literalSearch.'%']);
                    $query->whereHas('memberOne', $nameMatch)->orWhereHas('memberTwo', $nameMatch);
                });
            })
            ->with(['conversation.memberOne:id,name', 'conversation.memberTwo:id,name', 'conversation.latestMessage'])
            ->orderByDesc('conversation_id')->paginate(50)->withQueryString()
            ->through(function (ConversationParticipant $participant) use ($viewer): array {
                $conversation = $participant->conversation;
                $member = $conversation->member_one_id === $viewer->id ? $conversation->memberTwo : $conversation->memberOne;
                $latest = $conversation->latestMessage;

                return [
                    'id' => $conversation->id,
                    'member' => ['id' => $member->id, 'name' => $member->name],
                    'latestMessage' => $latest ? [
                        'body' => $latest->body,
                        'createdAt' => $latest->created_at?->toISOString(),
                    ] : null,
                    'unreadCount' => (int) $participant->getAttribute('unread_count'),
                ];
            });
    }

    /** @param array<string, mixed> $props */
    private function privatePage(Request $request, string $component, array $props): Response
    {
        $response = Inertia::render($component, $props)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
