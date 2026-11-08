import {
    Head,
    Link,
    router,
    useForm,
    useHttp,
    usePage,
    usePoll,
} from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
    const log = useRef<HTMLDivElement>(null);
    const renderedCount = useRef(0);
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
        const nearBottom =
            root.scrollHeight - root.scrollTop - root.clientHeight < 100;
        if (renderedCount.current === 0 || nearBottom) {
            root.scrollTop = root.scrollHeight;
        }
        renderedCount.current = thread.messages.length;
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
            className="flex h-[72vh] max-h-[calc(100dvh-18rem)] min-h-0 flex-col border border-border bg-card"
            aria-label={`Conversation with ${thread.member.name}`}
        >
            <header className="flex items-center gap-3 border-b border-border px-4 py-3">
                <Link
                    href={messagesIndex({
                        query: search ? { q: search } : {},
                    })}
                    className="inline-flex min-h-11 items-center underline underline-offset-4 md:hidden"
                >
                    Back
                </Link>
                <h2 className="min-w-0 flex-1 truncate font-serif text-xl">
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
                className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"
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
                                className={`max-w-[85%] rounded-xl border border-border px-3 py-2 break-words whitespace-pre-wrap ${own ? 'ml-auto bg-secondary' : 'mr-auto bg-background'}`}
                            >
                                <p className="mb-1 text-xs text-muted-foreground">
                                    {own ? 'You' : message.senderName}
                                </p>
                                <p>{message.body}</p>
                            </article>
                        );
                    })
                )}
            </div>
            {thread.canSend ? (
                <form onSubmit={submit} className="border-t border-border p-3">
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
                    <div className="flex items-end gap-2">
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
                            className="max-h-[4.5rem] min-h-11 flex-1 resize-none overflow-y-auto rounded-md border border-input bg-background px-3 py-2 text-base leading-6 focus-visible:outline-2 focus-visible:outline-ring"
                        />
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.body.trim().length === 0
                            }
                            className="min-h-11"
                        >
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
            <div className="py-8">
                <header className="mb-5 flex items-center justify-between gap-4">
                    <div>
                        <h1 className="font-serif text-3xl">Messages</h1>
                        <p className="text-sm text-muted-foreground">
                            Private conversations with members who follow each
                            other.
                        </p>
                    </div>
                    <Link
                        href={dashboard()}
                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                    >
                        Home
                    </Link>
                </header>
                <div className="grid gap-4 md:grid-cols-[minmax(15rem,21rem)_1fr]">
                    <aside
                        className={thread ? 'hidden md:block' : 'block'}
                        aria-label="Existing conversations"
                    >
                        <form
                            onSubmit={searchConversations}
                            className="mb-3 flex gap-2"
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
                                className="min-h-11 min-w-0 flex-1 rounded-md border border-input bg-background px-3 text-sm"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                className="min-h-11"
                            >
                                Search
                            </Button>
                        </form>
                        {conversations.data.length === 0 ? (
                            <p className="border border-border p-4 text-sm text-muted-foreground">
                                {search
                                    ? 'No existing conversations match that name.'
                                    : 'No conversations yet. Visit a mutually followed member’s profile to message them.'}
                            </p>
                        ) : (
                            <ul className="divide-y divide-border border border-border">
                                {conversations.data.map((item) => (
                                    <li key={item.id}>
                                        <Link
                                            href={messagesShow(item.id, {
                                                query: search
                                                    ? { q: search }
                                                    : {},
                                            })}
                                            className={`block min-h-20 px-3 py-3 hover:bg-muted/50 focus-visible:outline-2 focus-visible:outline-ring ${thread?.id === item.id ? 'bg-muted/60' : ''}`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="truncate font-medium">
                                                    {item.member.name}
                                                </span>
                                                {item.unreadCount > 0 && (
                                                    <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground">
                                                        {item.unreadCount}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                {item.latestMessage?.body ??
                                                    'Conversation started'}
                                            </p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {(conversations.prev_page_url ||
                            conversations.next_page_url) && (
                            <nav
                                aria-label="Conversation pages"
                                className="flex justify-between py-2"
                            >
                                {conversations.prev_page_url && (
                                    <Link
                                        href={conversations.prev_page_url}
                                        preserveScroll
                                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                                    >
                                        Previous
                                    </Link>
                                )}
                                {conversations.next_page_url && (
                                    <Link
                                        href={conversations.next_page_url}
                                        preserveScroll
                                        className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                                    >
                                        Next
                                    </Link>
                                )}
                            </nav>
                        )}
                    </aside>
                    <div className={thread ? 'block' : 'hidden md:block'}>
                        {thread ? (
                            <ThreadPanel
                                key={thread.id}
                                thread={thread}
                                search={search}
                            />
                        ) : (
                            <div className="hidden min-h-[65vh] items-center justify-center border border-border bg-card text-sm text-muted-foreground md:flex">
                                Choose a conversation to read and reply.
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
