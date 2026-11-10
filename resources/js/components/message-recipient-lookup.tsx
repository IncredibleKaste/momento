import { Link } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import {
    index as messagesIndex,
    recipients as messageRecipients,
    show as messagesShow,
    store as startMessage,
} from '@/routes/messages';

type RequestState =
    | 'direct'
    | 'request'
    | 'outgoing'
    | 'incoming'
    | 'closed'
    | 'reopen'
    | 'unavailable';

const requestStateLabels: Record<RequestState, string> = {
    direct: 'Message',
    request: 'Send request',
    outgoing: 'Request sent',
    incoming: 'View request',
    closed: 'Closed',
    reopen: 'Allow a new request',
    unavailable: 'Messaging unavailable',
};

export type MessageRecipient = {
    id: number;
    name: string;
    canMessage: boolean;
    requestState: RequestState;
    requestId: number | null;
    conversationId: number | null;
    canReopen: boolean;
};

type Props = {
    search: string;
    onSearchChange: (value: string) => void;
};

type Response = { data: MessageRecipient[] };

export default function MessageRecipientLookup({
    search,
    onSearchChange,
}: Props) {
    const id = useId();
    const [results, setResults] = useState<MessageRecipient[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const requestSequence = useRef(0);

    useEffect(() => {
        const query = search.trim();
        const sequence = ++requestSequence.current;
        if (query === '') {
            setResults([]);
            setFailed(false);
            setLoading(false);

            return;
        }

        setResults([]);
        setFailed(false);
        const queryLength = Array.from(query).length;
        if (queryLength < 2 || queryLength > 80) {
            setLoading(false);

            return;
        }
        setLoading(false);
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            void fetch(messageRecipients.url({ query: { q: query } }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('Recipient search failed. Try again.');
                    }

                    return (await response.json()) as Response;
                })
                .then((payload) => {
                    if (
                        !controller.signal.aborted &&
                        requestSequence.current === sequence &&
                        Array.isArray(payload.data)
                    ) {
                        setResults(payload.data);
                    }
                })
                .catch((error: unknown) => {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }
                    if (requestSequence.current === sequence) {
                        setResults([]);
                        setFailed(true);
                    }
                })
                .finally(() => {
                    if (
                        !controller.signal.aborted &&
                        requestSequence.current === sequence
                    ) {
                        setLoading(false);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [search]);

    function destination(recipient: MessageRecipient) {
        return messagesIndex.url({ query: { recipient: recipient.id } });
    }

    function actionLabel(recipient: MessageRecipient) {
        if (recipient.canMessage) {
            return recipient.conversationId ? 'Open chat' : 'Start chat';
        }

        return requestStateLabels[recipient.requestState] ?? null;
    }

    return (
        <section aria-labelledby={`${id}-heading`} className="mt-5 space-y-2">
            <h2 id={`${id}-heading`} className="text-sm font-semibold">
                Find someone
            </h2>
            <label htmlFor={`${id}-search`} className="sr-only">
                Search verified members by name
            </label>
            <input
                id={`${id}-search`}
                type="search"
                value={search}
                onChange={(event) => onSearchChange(event.target.value)}
                autoComplete="off"
                placeholder="Type a member name"
                className="min-h-11 w-full rounded-full border border-border bg-card px-4 text-base outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-[601px]:text-sm"
            />
            <div aria-live="polite" aria-busy={loading}>
                {search.trim() === '' ? (
                    <p className="px-2 py-3 text-xs leading-relaxed text-muted-foreground">
                        Type at least 2 characters to find verified members.
                        Suggestions stay hidden until then.
                    </p>
                ) : Array.from(search.trim()).length > 80 ? (
                    <p
                        role="alert"
                        className="px-2 py-3 text-xs text-destructive"
                    >
                        Search by a name of 80 characters or fewer.
                    </p>
                ) : Array.from(search.trim()).length < 2 ? (
                    <p className="px-2 py-3 text-xs text-muted-foreground">
                        Type at least 2 characters to search.
                    </p>
                ) : loading ? (
                    <p
                        role="status"
                        className="px-2 py-3 text-xs text-muted-foreground"
                    >
                        Searching…
                    </p>
                ) : failed ? (
                    <p
                        role="alert"
                        className="px-2 py-3 text-xs text-destructive"
                    >
                        Recipient search could not be loaded. Try again.
                    </p>
                ) : results.length === 0 ? (
                    <p className="px-2 py-3 text-xs text-muted-foreground">
                        No eligible verified members match that name.
                    </p>
                ) : (
                    <ul className="space-y-1" aria-label="Matching members">
                        {results.map((recipient) => (
                            <li
                                key={recipient.id}
                                className="flex min-h-14 min-w-0 items-center justify-between gap-2 rounded-md px-2 py-2 hover:bg-muted"
                            >
                                <span className="min-w-0 flex-1 text-sm font-medium wrap-anywhere">
                                    {recipient.name}
                                </span>
                                {recipient.canMessage &&
                                recipient.conversationId ? (
                                    <Link
                                        href={messagesShow(
                                            recipient.conversationId,
                                        )}
                                        prefetch={false}
                                        className="inline-flex min-h-11 shrink-0 items-center rounded-md px-3 text-xs underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        {actionLabel(recipient)}
                                    </Link>
                                ) : recipient.canMessage ? (
                                    <Link
                                        href={startMessage(recipient.id)}
                                        method="post"
                                        as="button"
                                        prefetch={false}
                                        className="inline-flex min-h-11 shrink-0 items-center rounded-md px-3 text-xs underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        {actionLabel(recipient)}
                                    </Link>
                                ) : actionLabel(recipient) ? (
                                    <Link
                                        href={destination(recipient)}
                                        prefetch={false}
                                        className="inline-flex min-h-11 shrink-0 items-center rounded-md px-3 text-xs underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        {actionLabel(recipient)}
                                    </Link>
                                ) : (
                                    <span className="px-3 text-xs text-muted-foreground">
                                        Messaging unavailable
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
