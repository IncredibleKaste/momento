<?php

namespace App\Http\Controllers;

use App\Actions\ManageConversation;
use App\Actions\ManageConversationAttachments;
use App\Actions\ManageConversationRequests;
use App\Models\Conversation;
use App\Models\ConversationAttachmentSend;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\ConversationRequest;
use App\Models\User;
use App\Services\ConversationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ConversationController extends Controller
{
    public function index(Request $request, ManageConversationRequests $requests, ConversationAccess $access): Response
    {
        $viewer = $request->user();
        $search = $request->string('q')->trim()->limit(80)->toString();
        $recipientId = $request->query('recipient');
        abort_if($recipientId !== null && (! is_string($recipientId) || preg_match('/^[1-9][0-9]*$/D', $recipientId) !== 1), 404);
        $selectedRecipient = null;

        if (is_string($recipientId)) {
            $recipient = User::query()->whereKey((int) $recipientId)->whereNotNull('email_verified_at')->firstOrFail();
            $mode = $access->interactionMode($viewer, $recipient);
            abort_if($mode === 'unavailable', 404);
            $latest = $access->latestRequest($viewer->id, $recipient->id);
            $selectedRecipient = [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'mode' => $mode,
                'requestId' => $latest?->id,
                'conversationId' => Conversation::query()->where('member_one_id', min($viewer->id, $recipient->id))
                    ->where('member_two_id', max($viewer->id, $recipient->id))->value('id'),
                'canReopen' => $mode === 'reopen' && $latest?->recipient_id === $viewer->id,
            ];
        }

        $incoming = $requests->pageFor($viewer, true);
        $outgoing = $requests->pageFor($viewer, false);
        $requestCounts = $requests->activePendingCounts($viewer);

        return $this->privatePage($request, 'messages/index', [
            'conversations' => $this->rail($viewer, $search),
            'search' => $search,
            'thread' => null,
            'selectedRecipient' => $selectedRecipient,
            'requests' => [
                'incomingCount' => $requestCounts['incoming'],
                'outgoingCount' => $requestCounts['outgoing'],
                'incoming' => $this->requestItems($incoming, $viewer, $requests),
                'outgoing' => $this->requestItems($outgoing, $viewer, $requests),
            ],
        ]);
    }

    public function recipients(Request $request, ConversationAccess $access): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80', 'regex:/\A[^\x00]*\z/u'],
        ]);
        $viewer = $request->user();
        $search = trim($validated['q']);
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }
        $query = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $recipients = User::query()->select(['id', 'name', 'email_verified_at', 'message_requests_enabled'])
            ->where('id', '<>', $viewer->id)->whereNotNull('email_verified_at')
            ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", ['%'.$query.'%'])
            ->where(function ($eligible) use ($viewer): void {
                $eligible->where('message_requests_enabled', true)
                    ->orWhereExists(function ($firstFollow) use ($viewer): void {
                        $firstFollow->selectRaw('1')->from('follows as first_follow')
                            ->whereColumn('first_follow.followed_id', 'users.id')
                            ->where('first_follow.follower_id', $viewer->id)
                            ->where('first_follow.status', 'approved')
                            ->whereExists(function ($secondFollow) use ($viewer): void {
                                $secondFollow->selectRaw('1')->from('follows as second_follow')
                                    ->whereColumn('second_follow.follower_id', 'users.id')
                                    ->where('second_follow.followed_id', $viewer->id)
                                    ->where('second_follow.status', 'approved');
                            });
                    });

                foreach ([ConversationRequest::Accepted, ConversationRequest::Pending] as $status) {
                    $eligible->orWhereExists(function ($latest) use ($viewer, $status): void {
                        $latest->selectRaw('1')->from('conversation_requests as lookup_request')
                            ->where('lookup_request.status', $status)
                            ->where(function ($pair) use ($viewer): void {
                                $pair->where(function ($direction) use ($viewer): void {
                                    $direction->where('lookup_request.requester_id', $viewer->id)
                                        ->whereColumn('lookup_request.recipient_id', 'users.id');
                                })->orWhere(function ($direction) use ($viewer): void {
                                    $direction->whereColumn('lookup_request.requester_id', 'users.id')
                                        ->where('lookup_request.recipient_id', $viewer->id);
                                });
                            })
                            ->whereNotExists(function ($newer): void {
                                $newer->selectRaw('1')->from('conversation_requests as newer_lookup_request')
                                    ->whereColumn('newer_lookup_request.member_one_id', 'lookup_request.member_one_id')
                                    ->whereColumn('newer_lookup_request.member_two_id', 'lookup_request.member_two_id')
                                    ->whereColumn('newer_lookup_request.id', '>', 'lookup_request.id');
                            });
                    });
                }

                foreach ([ConversationRequest::Declined, ConversationRequest::Stopped] as $status) {
                    $eligible->orWhereExists(function ($latest) use ($viewer, $status): void {
                        $latest->selectRaw('1')->from('conversation_requests as lookup_request')
                            ->where('lookup_request.status', $status)
                            ->where('lookup_request.recipient_id', $viewer->id)
                            ->whereColumn('lookup_request.requester_id', 'users.id')
                            ->whereNull('lookup_request.reopened_at')
                            ->whereNotExists(function ($newer): void {
                                $newer->selectRaw('1')->from('conversation_requests as newer_lookup_request')
                                    ->whereColumn('newer_lookup_request.member_one_id', 'lookup_request.member_one_id')
                                    ->whereColumn('newer_lookup_request.member_two_id', 'lookup_request.member_two_id')
                                    ->whereColumn('newer_lookup_request.id', '>', 'lookup_request.id');
                            });
                    });
                }
            })
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM follows WHERE follows.follower_id = ? AND follows.followed_id = users.id AND follows.status = ?) THEN 0 ELSE 1 END', [$viewer->id, 'approved'])
            ->orderBy('name')->orderBy('id')->limit(5)->get()
            ->map(function (User $member) use ($viewer, $access): array {
                $latest = $access->latestRequest($viewer->id, $member->id);
                $conversation = Conversation::query()->where('member_one_id', min($viewer->id, $member->id))
                    ->where('member_two_id', max($viewer->id, $member->id))->first(['id']);

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'canMessage' => $access->canSend($viewer, $member),
                    'requestState' => $access->interactionMode($viewer, $member),
                    'requestId' => $latest?->id,
                    'conversationId' => $conversation?->id,
                    'canReopen' => $latest?->recipient_id === $viewer->id && $access->interactionMode($viewer, $member) === 'reopen',
                ];
            })->values();

        return response()->json(['data' => $recipients]);
    }

    public function show(Request $request, Conversation $conversation, ManageConversation $conversations, ManageConversationAttachments $attachments, ConversationAccess $access): Response
    {
        $viewer = $request->user();
        $participant = $conversations->participant($conversation, $viewer);
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
        $canSend = $access->canSend($viewer, $member);
        $requestPermission = $access->requestPermission($viewer, $member);
        $attachmentProjection = $attachments->projection($messages->pluck('id')->all(), $canSend);

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
                    ...$attachmentProjection[$message->id],
                ])->all(),
                'unreadCount' => $unreadCount,
                'lastReadMessageId' => $participant->last_read_message_id,
                'nextBefore' => $hasOlder ? $messages->first()?->id : null,
                'isLatestPage' => $before === null,
                'canSend' => $canSend,
                'requestPermission' => $requestPermission,
                'attachmentCapabilities' => $attachments->capabilities(),
                'pendingAttachmentSend' => $this->attachmentStatus($attachments->pendingFor($viewer, $conversation)),
            ],
        ]);
    }

    public function store(Request $request, User $member, ManageConversation $conversations, ConversationAccess $access): RedirectResponse
    {
        $mode = $access->interactionMode($request->user(), $member);
        if ($mode !== 'direct') {
            abort_unless($mode !== 'unavailable', 404);

            return to_route('messages.index', ['recipient' => $member->id]);
        }

        $conversation = $conversations->start($request->user(), $member);

        return to_route('messages.show', $conversation);
    }

    public function sendRequest(Request $request, User $member, ManageConversationRequests $requests): RedirectResponse
    {
        if ($request->exists('attachments') || $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['attachments' => 'Files can only be sent after a conversation is available.']);
        }
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500', 'regex:/\A[^\x00]*\z/u'],
            'nonce' => ['required', 'uuid'],
        ]);
        $result = $requests->initiate($request->user(), $member, $validated['body'], $validated['nonce']);

        if ($result instanceof Conversation) {
            return to_route('messages.show', $result);
        }

        if ($result->status === ConversationRequest::Accepted) {
            $conversation = Conversation::query()->where('member_one_id', $result->member_one_id)
                ->where('member_two_id', $result->member_two_id)->firstOrFail();

            return to_route('messages.show', $conversation);
        }

        return to_route('messages.index', ['recipient' => $member->id]);
    }

    public function acceptRequest(Request $request, ConversationRequest $conversationRequest, ManageConversationRequests $requests): RedirectResponse
    {
        $result = $requests->respond($request->user(), $conversationRequest->id, true);
        $conversation = Conversation::query()->where('member_one_id', $result->member_one_id)
            ->where('member_two_id', $result->member_two_id)->firstOrFail();

        return to_route('messages.show', $conversation);
    }

    public function declineRequest(Request $request, ConversationRequest $conversationRequest, ManageConversationRequests $requests): RedirectResponse
    {
        $requests->respond($request->user(), $conversationRequest->id, false);

        return to_route('messages.index');
    }

    public function stopRequest(Request $request, ConversationRequest $conversationRequest, ManageConversationRequests $requests): RedirectResponse
    {
        $requests->stop($request->user(), $conversationRequest->id);

        return to_route('messages.index');
    }

    public function reopenRequest(Request $request, ConversationRequest $conversationRequest, ManageConversationRequests $requests): RedirectResponse
    {
        $requests->reopen($request->user(), $conversationRequest->id);

        return to_route('messages.index');
    }

    public function send(Request $request, Conversation $conversation, ManageConversation $conversations): RedirectResponse
    {
        $conversations->participant($conversation, $request->user());
        if ($request->exists('attachments') || $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['attachments' => 'Use the attachment message endpoint to send files.']);
        }
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
            ->with(['conversation.memberOne:id,name', 'conversation.memberTwo:id,name', 'conversation.latestMessage.attachmentSend'])
            ->orderByDesc('conversation_id')->paginate(50)->withQueryString()
            ->through(function (ConversationParticipant $participant) use ($viewer): array {
                $conversation = $participant->conversation;
                $member = $conversation->member_one_id === $viewer->id ? $conversation->memberTwo : $conversation->memberOne;
                $latest = $conversation->latestMessage;
                $latestBody = $latest->body ?? '';
                if ($latestBody === '' && $latest?->attachmentSend?->status === 'sent') {
                    $latestBody = 'Attachment';
                }

                return [
                    'id' => $conversation->id,
                    'member' => ['id' => $member->id, 'name' => $member->name],
                    'latestMessage' => $latest ? [
                        'body' => $latestBody,
                        'createdAt' => $latest->created_at?->toISOString(),
                    ] : null,
                    'unreadCount' => (int) $participant->getAttribute('unread_count'),
                ];
            });
    }

    /** @return array{nonce: string, status: string, messageId: int|null, error: string|null}|null */
    private function attachmentStatus(?ConversationAttachmentSend $send): ?array
    {
        if ($send === null) {
            return null;
        }

        return [
            'nonce' => $send->nonce,
            'status' => 'processing',
            'messageId' => $send->message_id,
            'error' => null,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, ConversationRequest>  $page
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     member: array{id: int, name: string},
     *     body: string,
     *     expiresAt: string|null,
     *     status: string|null,
     *     canAccept: bool,
     *     canDecline: bool,
     *     canStop: bool,
     *     canReopen: bool
     * }>
     */
    private function requestItems(LengthAwarePaginator $page, User $viewer, ManageConversationRequests $requests): LengthAwarePaginator
    {
        return $page->through(function (ConversationRequest $item) use ($viewer, $requests): array {
            $incoming = $item->recipient_id === $viewer->id;
            $member = $incoming ? $item->requester : $item->recipient;
            $status = $requests->effectiveStatus($item);
            $active = $status === ConversationRequest::Pending;
            $canReopen = $incoming && in_array($status, [ConversationRequest::Declined, ConversationRequest::Expired, ConversationRequest::Stopped], true)
                && $item->reopened_at === null && $viewer->message_requests_enabled;

            return [
                'id' => $item->id,
                'member' => ['id' => $member->id, 'name' => $member->name],
                'body' => $item->body,
                'expiresAt' => $item->expires_at->toISOString(),
                'status' => $status,
                'canAccept' => $incoming && $active,
                'canDecline' => $incoming && $active,
                'canStop' => $active || $status === ConversationRequest::Accepted,
                'canReopen' => $canReopen,
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
