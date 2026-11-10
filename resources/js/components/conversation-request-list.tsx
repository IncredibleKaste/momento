import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { RouteFormDefinition } from '@/wayfinder';
import {
    accept as acceptRequest,
    decline as declineRequest,
    reopen as reopenRequest,
    stop as stopRequest,
} from '@/routes/message-requests';
import { index as messagesIndex } from '@/routes/messages';

export type ConversationRequestItem = {
    id: number;
    member: { id: number; name: string };
    body: string;
    expiresAt: string | null;
    status: string;
    canAccept: boolean;
    canDecline: boolean;
    canStop: boolean;
    canReopen: boolean;
};

export type ConversationRequestPage = {
    data: ConversationRequestItem[];
    next_page_url: string | null;
    prev_page_url: string | null;
};

function RequestActionForm({
    action,
    label,
    variant = 'default',
    busy,
    onStart,
    onFinish,
}: {
    action: RouteFormDefinition<'post'>;
    label: string;
    variant?: 'default' | 'outline';
    busy: boolean;
    onStart: () => void;
    onFinish: () => void;
}) {
    const [failed, setFailed] = useState(false);
    const [transportFailure, setTransportFailure] = useState<string | null>(
        null,
    );

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            onStart={() => {
                setFailed(false);
                setTransportFailure(null);
                onStart();
            }}
            onError={() => setFailed(true)}
            onHttpException={(response) => {
                setFailed(true);
                setTransportFailure(
                    response.status === 429
                        ? 'Too many request changes. Wait a minute, then try again.'
                        : response.status === 419
                          ? 'Your session needs refreshing. Refresh this page, then try again.'
                          : 'The request action could not be completed. Refresh the request list and try again.',
                );
            }}
            onNetworkError={() => {
                setFailed(true);
                setTransportFailure(
                    'Connection interrupted. Refresh the request list before retrying.',
                );
            }}
            onSuccess={() => {
                setFailed(false);
                setTransportFailure(null);
            }}
            onFinish={onFinish}
        >
            {({ processing, errors }) => (
                <div className="space-y-1">
                    <Button
                        type="submit"
                        variant={variant}
                        disabled={processing || busy}
                        className="min-h-11"
                    >
                        {label}
                    </Button>
                    {failed && (
                        <p role="alert" className="text-xs text-destructive">
                            {Object.values(errors).join(' ') ||
                                transportFailure ||
                                'The request action could not be completed. Refresh the request list and try again.'}
                        </p>
                    )}
                </div>
            )}
        </Form>
    );
}

export function ConversationRequestActionButton({
    action,
    label,
    variant = 'default',
}: {
    action: RouteFormDefinition<'post'>;
    label: string;
    variant?: 'default' | 'outline';
}) {
    const [busy, setBusy] = useState(false);

    return (
        <RequestActionForm
            action={action}
            label={label}
            variant={variant}
            busy={busy}
            onStart={() => setBusy(true)}
            onFinish={() => setBusy(false)}
        />
    );
}

export function ConversationRequestActions({
    request,
}: {
    request: ConversationRequestItem;
}) {
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex flex-wrap gap-2">
            {request.canAccept && (
                <RequestActionForm
                    action={acceptRequest.form(request.id)}
                    label="Accept"
                    busy={busy}
                    onStart={() => setBusy(true)}
                    onFinish={() => setBusy(false)}
                />
            )}
            {request.canDecline && (
                <RequestActionForm
                    action={declineRequest.form(request.id)}
                    label="Decline"
                    variant="outline"
                    busy={busy}
                    onStart={() => setBusy(true)}
                    onFinish={() => setBusy(false)}
                />
            )}
            {request.canStop && (
                <RequestActionForm
                    action={stopRequest.form(request.id)}
                    label={
                        request.status === 'accepted'
                            ? 'End request approval'
                            : 'Stop request'
                    }
                    variant="outline"
                    busy={busy}
                    onStart={() => setBusy(true)}
                    onFinish={() => setBusy(false)}
                />
            )}
            {request.canReopen && (
                <RequestActionForm
                    action={reopenRequest.form(request.id)}
                    label="Allow a new request"
                    variant="outline"
                    busy={busy}
                    onStart={() => setBusy(true)}
                    onFinish={() => setBusy(false)}
                />
            )}
        </div>
    );
}

function RequestPageLinks({
    page,
    label,
}: {
    page: ConversationRequestPage;
    label: string;
}) {
    if (!page.prev_page_url && !page.next_page_url) return null;

    return (
        <nav
            aria-label={`${label} request pages`}
            className="flex justify-between gap-3 py-2"
        >
            {page.prev_page_url ? (
                <Link
                    href={page.prev_page_url}
                    preserveScroll
                    prefetch={false}
                    className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                >
                    Previous
                </Link>
            ) : (
                <span />
            )}
            {page.next_page_url && (
                <Link
                    href={page.next_page_url}
                    preserveScroll
                    prefetch={false}
                    className="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                >
                    Next
                </Link>
            )}
        </nav>
    );
}

function expirationLabel(expiresAt: string | null) {
    if (!expiresAt) return null;
    const date = new Date(expiresAt);
    if (!Number.isFinite(date.getTime())) return null;

    return `${date.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
}

function RequestList({
    page,
    direction,
}: {
    page: ConversationRequestPage;
    direction: 'incoming' | 'outgoing';
}) {
    const emptyMessage =
        direction === 'incoming'
            ? 'No incoming requests.'
            : 'No sent requests.';

    return (
        <section
            aria-label={
                direction === 'incoming'
                    ? 'Incoming message requests'
                    : 'Sent message requests'
            }
            className="space-y-2"
        >
            {page.data.length === 0 ? (
                <p className="px-2 py-3 text-xs text-muted-foreground">
                    {emptyMessage}
                </p>
            ) : (
                <ul className="space-y-2">
                    {page.data.map((request) => (
                        <li
                            key={request.id}
                            className="space-y-2 rounded-md border border-border bg-card p-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold wrap-anywhere">
                                        {request.member.name}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {request.status === 'pending'
                                            ? direction === 'incoming'
                                                ? 'Wants to start a conversation'
                                                : 'Waiting for a response'
                                            : request.status === 'accepted'
                                              ? 'Accepted'
                                              : request.status === 'expired'
                                                ? 'Expired'
                                                : request.status === 'declined'
                                                  ? 'Declined'
                                                  : 'Closed'}
                                    </p>
                                </div>
                                <Link
                                    href={messagesIndex.url({
                                        query: { recipient: request.member.id },
                                    })}
                                    prefetch={false}
                                    className="inline-flex min-h-11 shrink-0 items-center text-xs underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    Open
                                </Link>
                            </div>
                            <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                                {request.body}
                            </p>
                            {request.status === 'pending' &&
                                expirationLabel(request.expiresAt) && (
                                    <p className="text-xs text-muted-foreground">
                                        Expires{' '}
                                        <time
                                            dateTime={
                                                request.expiresAt ?? undefined
                                            }
                                        >
                                            {expirationLabel(request.expiresAt)}
                                        </time>
                                    </p>
                                )}
                            <ConversationRequestActions request={request} />
                        </li>
                    ))}
                </ul>
            )}
            <RequestPageLinks
                page={page}
                label={direction === 'incoming' ? 'Incoming' : 'Sent'}
            />
        </section>
    );
}

export default function ConversationRequestList({
    incoming,
    outgoing,
    incomingCount,
    outgoingCount,
}: {
    incoming: ConversationRequestPage;
    outgoing: ConversationRequestPage;
    incomingCount: number;
    outgoingCount: number;
}) {
    const [view, setView] = useState<'incoming' | 'outgoing'>('incoming');
    const page = view === 'incoming' ? incoming : outgoing;

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-1 rounded-md bg-muted p-1">
                <button
                    type="button"
                    aria-pressed={view === 'incoming'}
                    onClick={() => setView('incoming')}
                    className={`min-h-11 rounded px-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${view === 'incoming' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'}`}
                >
                    Incoming ({incomingCount})
                </button>
                <button
                    type="button"
                    aria-pressed={view === 'outgoing'}
                    onClick={() => setView('outgoing')}
                    className={`min-h-11 rounded px-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${view === 'outgoing' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'}`}
                >
                    Sent ({outgoingCount})
                </button>
            </div>
            <RequestList page={page} direction={view} />
        </div>
    );
}
