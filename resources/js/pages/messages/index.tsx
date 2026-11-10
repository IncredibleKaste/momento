import {
    Head,
    Link,
    router,
    useForm,
    useHttp,
    usePage,
    usePoll,
} from '@inertiajs/react';
import { ArrowLeft, Search, Send } from 'lucide-react';
import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import {
    index as messagesIndex,
    read as markRead,
    send as sendMessage,
    show as messagesShow,
} from '@/routes/messages';

type ConversationItem = {
    id: number;
    member: { id: number; name: string };
    latestMessage: { body: string; createdAt: string | null } | null;
    unreadCount: number;
};

type Message = {
    id: number;
    senderId: number;
    senderName: string;
    body: string;
    createdAt: string | null;
};

type Thread = {
    id: number;
    member: { id: number; name: string };
    messages: Message[];
    unreadCount: number;
    lastReadMessageId: number | null;
    nextBefore: number | null;
    isLatestPage: boolean;
    canSend: boolean;
};

type Page<T> = {
    data: T[];
    next_page_url: string | null;
    prev_page_url: string | null;
};

type Props = {
    conversations: Page<ConversationItem>;
    search: string;
    thread: Thread | null;
};

function ThreadPanel({ thread, search }: { thread: Thread; search: string }) {
    const { auth } = usePage().props;
    const initials = useInitials();
    const log = useRef<HTMLDivElement>(null);
    const renderedThreadId = useRef<number | null>(null);
    const renderedLastMessageId = useRef<number | null>(null);
    const wasNearBottom = useRef(true);
    const submitLock = useRef(false);
    const readLock = useRef(false);
    const pendingRead = useRef(0);
    const composer = useRef<HTMLTextAreaElement>(null);
    const [failed, setFailed] = useState(false);
    const form = useForm({ body: '', nonce: crypto.randomUUID() });
    const readRequest = useHttp();
    const poll = usePoll(
        5000,
        { only: ['thread', 'conversations'] },
        { autoStart: false, mode: 'rest' },
    );

    useEffect(() => {
        function updatePolling() {
            if (document.visibilityState === 'visible') poll.start();
            else poll.stop();
        }

        updatePolling();
        document.addEventListener('visibilitychange', updatePolling);

        return () =>
            document.removeEventListener('visibilitychange', updatePolling);
    }, [poll.start, poll.stop]);

    useEffect(() => {
        const root = log.current;
        if (!root) return;
        const lastMessageId = thread.messages.at(-1)?.id ?? null;
        const isNewThread = renderedThreadId.current !== thread.id;
        const hasNewMessage =
            lastMessageId !== null &&
            lastMessageId !== renderedLastMessageId.current;

        if (isNewThread || (hasNewMessage && wasNearBottom.current)) {
            root.scrollTop = root.scrollHeight;
            wasNearBottom.current = true;
        }
        renderedThreadId.current = thread.id;
        renderedLastMessageId.current = lastMessageId;
    }, [thread.id, thread.messages.length, thread.messages.at(-1)?.id]);

    useEffect(() => {
        const root = log.current;
        if (!root) return;
        const visibleIds = new Set<number>();
        function markVisibleRead() {
            const messageId = pendingRead.current;
            if (
                document.visibilityState !== 'visible' ||
                readLock.current ||
                messageId <= (thread.lastReadMessageId ?? 0)
            )
                return;
            readLock.current = true;
            void readRequest
                .post(markRead.url([thread.id, messageId]), {
                    onFinish: () => {
                        readLock.current = false;
                        if (pendingRead.current > messageId) markVisibleRead();
                    },
                })
                .catch(() => {});
        }
        const observer = new IntersectionObserver(
            (entries) => {
                if (document.visibilityState !== 'visible') return;
                for (const entry of entries) {
                    const id = Number(
                        (entry.target as HTMLElement).dataset.messageId,
                    );
                    if (
                        entry.isIntersecting &&
                        entry.intersectionRect.width > 0 &&
                        entry.intersectionRect.height > 0
                    )
                        visibleIds.add(id);
                    else visibleIds.delete(id);
                }
                pendingRead.current = Math.max(0, ...visibleIds);
                markVisibleRead();
            },
            { threshold: 0 },
        );
        root.querySelectorAll<HTMLElement>(
            '[data-incoming-message="true"]',
        ).forEach((message) => observer.observe(message));
        function onVisibilityChange() {
            if (document.visibilityState === 'visible') markVisibleRead();
        }
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            observer.disconnect();
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [
        thread.id,
        thread.messages[0]?.id,
        thread.messages.at(-1)?.id,
        thread.lastReadMessageId,
        readRequest.post,
    ]);

    function submit(event?: FormEvent) {
        event?.preventDefault();
        if (
            form.processing ||
            submitLock.current ||
            form.data.body.trim().length === 0
        )
            return;
        submitLock.current = true;
        setFailed(false);
        form.post(sendMessage.url(thread.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData({ body: '', nonce: crypto.randomUUID() });
                if (composer.current) composer.current.style.height = 'auto';
                form.clearErrors();
                setFailed(false);
            },
            onError: () => setFailed(true),
            onNetworkError: () => setFailed(true),
            onHttpException: () => setFailed(true),
            onFinish: () => {
                submitLock.current = false;
            },
        });
    }

    function changeDraft(body: string) {
        if (failed && body !== form.data.body) {
            form.setData('nonce', crypto.randomUUID());
            form.clearErrors();
            setFailed(false);
        }
        form.setData('body', body);
        if (composer.current) {
            composer.current.style.height = 'auto';
            composer.current.style.height = `${Math.min(composer.current.scrollHeight, 72)}px`;
        }
    }

    function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing &&
            event.nativeEvent.keyCode !== 229
        ) {
            event.preventDefault();
            submit();
        }
    }

    return (
        <section
            className="chat-thread"
            aria-label={`Conversation with ${thread.member.name}`}
        >
            <header className="chat-thread-header">
                <Link
                    href={messagesIndex({
                        query: search ? { q: search } : {},
                    })}
                    prefetch={false}
                    className="inline-flex size-11 shrink-0 items-center justify-center rounded-md hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring min-[601px]:hidden"
                    aria-label="Back to conversations"
                >
                    <ArrowLeft aria-hidden="true" className="size-5" />
                </Link>
                <Avatar aria-hidden="true" className="size-[42px] shrink-0">
                    <AvatarFallback className="text-sm">
                        {initials(thread.member.name)}
                    </AvatarFallback>
                </Avatar>
                <h2 className="min-w-0 flex-1 text-sm font-bold wrap-anywhere">
                    {thread.member.name}
                </h2>
                {thread.unreadCount > 0 && (
                    <span className="text-xs text-muted-foreground">
                        {thread.unreadCount} unread
                    </span>
                )}
            </header>
            <div
                ref={log}
                onScroll={(event) => {
                    const root = event.currentTarget;
                    wasNearBottom.current =
                        root.scrollHeight - root.scrollTop - root.clientHeight <
                        100;
                }}
                className="chat-log"
                aria-live="polite"
                aria-relevant="additions text"
            >
                {!thread.isLatestPage && (
                    <div className="text-center">
                        <Link
                            href={messagesShow.url(thread.id, {
                                query: search ? { q: search } : {},
                            })}
                            preserveScroll
                            prefetch={false}
                            className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                        >
                            Return to latest messages
                        </Link>
                    </div>
                )}
                {thread.nextBefore && (
                    <div className="text-center">
                        <Link
                            href={messagesShow.url(thread.id, {
                                query: {
                                    ...(search ? { q: search } : {}),
                                    before: thread.nextBefore,
                                },
                            })}
                            preserveScroll
                            prefetch={false}
                            className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                        >
                            Load older messages
                        </Link>
                    </div>
                )}
                {thread.messages.length === 0 ? (
                    <p className="py-10 text-center text-sm text-muted-foreground">
                        Start the conversation.
                    </p>
                ) : (
                    thread.messages.map((message) => {
                        const own = message.senderId === auth.user.id;
                        return (
                            <article
                                key={message.id}
                                data-message-id={message.id}
                                data-incoming-message={
                                    !own ? 'true' : undefined
                                }
                                className={`chat-bubble ${own ? 'chat-bubble-own' : ''}`}
                            >
                                <p className="sr-only">
                                    {own ? 'You' : message.senderName}
                                </p>
                                <p>{message.body}</p>
                            </article>
                        );
                    })
                )}
            </div>
            {thread.canSend ? (
                <form onSubmit={submit} className="chat-composer">
                    <InputError
                        id="message-body-error"
                        message={form.errors.body}
                        role="alert"
                    />
                    {failed && (
                        <p
                            role="alert"
                            className="mb-2 text-sm text-destructive"
                        >
                            Message was not confirmed. Your draft is saved here;
                            retry with the same message.
                        </p>
                    )}
                    <label htmlFor="message-body" className="sr-only">
                        Message to {thread.member.name}
                    </label>
                    <div className="chat-input-shell">
                        <textarea
                            ref={composer}
                            id="message-body"
                            rows={1}
                            maxLength={4000}
                            value={form.data.body}
                            readOnly={form.processing}
                            aria-invalid={Boolean(form.errors.body)}
                            aria-describedby="message-body-help message-body-error"
                            onChange={(event) =>
                                changeDraft(event.target.value)
                            }
                            onKeyDown={handleKeyDown}
                            placeholder="Write a message…"
                            className="max-h-[4.5rem] min-h-11 w-full min-w-0 resize-none overflow-y-auto rounded-3xl border-0 bg-transparent py-2 pr-32 pl-4 text-base leading-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-[601px]:text-sm"
                        />
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.body.trim().length === 0
                            }
                            variant="ghost"
                            className="absolute right-0 bottom-0 min-h-11 min-w-11 rounded-full text-foreground"
                        >
                            <Send aria-hidden="true" className="size-5" />
                            {form.processing
                                ? 'Sending…'
                                : failed
                                  ? 'Retry'
                                  : 'Send'}
                        </Button>
                    </div>
                    <p
                        id="message-body-help"
                        className="mt-1 text-xs text-muted-foreground"
                    >
                        Enter sends. Shift+Enter adds a line.
                    </p>
                </form>
            ) : (
                <p className="border-t border-border p-4 text-sm text-muted-foreground">
                    You can read this conversation, but new messages are
                    unavailable because both members must be verified and follow
                    each other.
                </p>
            )}
        </section>
    );
}

export default function Messages({ conversations, search, thread }: Props) {
    const { auth } = usePage().props;
    const initials = useInitials();
    const [query, setQuery] = useState(search);

    useEffect(() => setQuery(search), [search]);

    function searchConversations(event: FormEvent) {
        event.preventDefault();
        router.get(
            messagesIndex.url({ query: query ? { q: query } : {} }),
            {},
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Messages" />
            <div className="messages-page">
                <h1 className="sr-only">Messages</h1>
                <div className="chat-layout">
                    <aside
                        className={`chat-conversations ${thread ? 'hidden min-[601px]:flex' : 'flex'}`}
                        aria-label="Existing conversations"
                    >
                        <header className="mb-[22px] min-w-0 shrink-0">
                            <p className="text-lg font-bold wrap-anywhere">
                                {auth.user.name}
                            </p>
                            <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                                Private conversations with members who follow
                                each other.
                            </p>
                        </header>
                        <form
                            onSubmit={searchConversations}
                            className="flex shrink-0 items-center rounded-full border border-border bg-card pl-3.5 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-ring"
                        >
                            <label
                                htmlFor="conversation-search"
                                className="sr-only"
                            >
                                Search conversations by member name
                            </label>
                            <input
                                id="conversation-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                maxLength={80}
                                placeholder="Search names"
                                className="min-h-11 min-w-0 flex-1 border-0 bg-transparent text-base outline-none min-[601px]:text-sm"
                            />
                            <Button
                                type="submit"
                                variant="ghost"
                                size="icon"
                                className="size-11 shrink-0 rounded-full"
                                aria-label="Search conversations"
                            >
                                <Search aria-hidden="true" className="size-5" />
                            </Button>
                        </form>
                        <h2 className="mt-6 mb-3 shrink-0 text-[15px] font-semibold">
                            Chats
                        </h2>
                        <div className="shrink-0 grow">
                            {conversations.data.length === 0 ? (
                                <p className="px-2 py-4 text-xs leading-relaxed text-muted-foreground">
                                    {search
                                        ? 'No existing conversations match that name.'
                                        : 'No conversations yet. Visit a mutually followed member’s profile to message them.'}
                                </p>
                            ) : (
                                <ul>
                                    {conversations.data.map((item) => (
                                        <li key={item.id}>
                                            <Link
                                                href={messagesShow(item.id, {
                                                    query: search
                                                        ? { q: search }
                                                        : {},
                                                })}
                                                prefetch={false}
                                                aria-current={
                                                    thread?.id === item.id
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className={`flex min-h-[70px] items-center gap-[11px] rounded-md px-2 py-3.5 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring ${thread?.id === item.id ? 'bg-muted' : ''}`}
                                            >
                                                <Avatar
                                                    aria-hidden="true"
                                                    className="size-[42px] shrink-0"
                                                >
                                                    <AvatarFallback className="text-sm">
                                                        {initials(
                                                            item.member.name,
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0 flex-1">
                                                    <span className="block truncate text-[13px] font-bold">
                                                        {item.member.name}
                                                    </span>
                                                    <p className="mt-1.5 truncate text-xs text-muted-foreground">
                                                        {item.latestMessage
                                                            ?.body ??
                                                            'Conversation started'}
                                                    </p>
                                                </div>
                                                {item.unreadCount > 0 && (
                                                    <span
                                                        aria-label={`${item.unreadCount} unread messages`}
                                                        className="chat-unread"
                                                    >
                                                        {item.unreadCount}
                                                    </span>
                                                )}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        {(conversations.prev_page_url ||
                            conversations.next_page_url) && (
                            <nav
                                aria-label="Conversation pages"
                                className="flex shrink-0 justify-between py-2"
                            >
                                {conversations.prev_page_url && (
                                    <Link
                                        href={conversations.prev_page_url}
                                        preserveScroll
                                        prefetch={false}
                                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                                    >
                                        Previous
                                    </Link>
                                )}
                                {conversations.next_page_url && (
                                    <Link
                                        href={conversations.next_page_url}
                                        preserveScroll
                                        prefetch={false}
                                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                                    >
                                        Next
                                    </Link>
                                )}
                            </nav>
                        )}
                        <Link
                            href={dashboard()}
                            prefetch={false}
                            className="inline-flex min-h-11 shrink-0 items-center text-xs underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-ring"
                        >
                            Home
                        </Link>
                    </aside>
                    <div
                        className={`chat-panel min-h-0 min-w-0 ${thread ? 'block' : 'hidden min-[601px]:block'}`}
                    >
                        {thread ? (
                            <ThreadPanel
                                key={thread.id}
                                thread={thread}
                                search={search}
                            />
                        ) : (
                            <div className="flex h-full flex-col items-center justify-center gap-3 p-[30px] text-center">
                                <h2 className="font-serif text-4xl">
                                    A little conversation.
                                </h2>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    Choose a conversation to read and reply.
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Messages.layout = {
    breadcrumbs: [{ title: 'Messages', href: messagesIndex() }],
};
