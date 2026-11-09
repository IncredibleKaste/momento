import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { create, cancel } from '@/routes/photo-posts';
import PhotoPostComposer from './composer';
import {
    canRequestPhotoPostCancel,
    canRetryPhotoPost,
    isPhotoPostPolling,
    type PhotoPost,
    type PhotoPostLimits,
} from './types';

type Props = {
    photoPost: PhotoPost;
    limits: PhotoPostLimits;
};

const statusCopy: Record<PhotoPost['status'], string> = {
    staging: 'Your photo is being secured.',
    queued: 'Your photo is waiting to be prepared.',
    processing: 'Your photo is being prepared. This may take a few minutes.',
    ready: 'Your photo is ready.',
    failed: 'Your photo could not be prepared.',
    cancel_requested: 'Cancellation requested. We are finishing safely.',
    cancelled: 'This post was cancelled.',
};

export default function ShowPhotoPost({ photoPost, limits }: Props) {
    const [canceling, setCanceling] = useState(false);
    const [cancelError, setCancelError] = useState(false);
    const isActive = isPhotoPostPolling(photoPost.status);
    const retryAvailable = canRetryPhotoPost(photoPost);
    const retryKey = retryAvailable ? photoPost.retryKey : null;
    const poll = usePoll(
        5000,
        { only: ['photoPost'] },
        { autoStart: false, mode: 'rest' },
    );

    useEffect(() => {
        function updatePolling() {
            if (isActive && document.visibilityState === 'visible')
                poll.start();
            else poll.stop();
        }

        updatePolling();
        document.addEventListener('visibilitychange', updatePolling);

        return () => {
            document.removeEventListener('visibilitychange', updatePolling);
            poll.stop();
        };
    }, [isActive, poll.start, poll.stop]);

    function requestCancel() {
        if (!canRequestPhotoPostCancel(photoPost, canceling)) return;

        setCancelError(false);
        router.post(
            cancel.url(photoPost.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setCanceling(true),
                onError: () => setCancelError(true),
                onNetworkError: () => setCancelError(true),
                onHttpException: () => setCancelError(true),
                onFinish: () => setCanceling(false),
            },
        );
    }

    return (
        <>
            <Head title="Photo post" />
            <div className="mx-auto max-w-3xl space-y-6 py-8">
                <header className="space-y-2">
                    <h1 className="font-serif text-3xl tracking-tight sm:text-4xl">
                        Photo post
                    </h1>
                </header>

                {isActive && (
                    <div
                        role="status"
                        aria-live="polite"
                        className="rounded-md border border-border bg-card p-4 text-sm"
                    >
                        <p>{statusCopy[photoPost.status]}</p>
                        {photoPost.status === 'processing' && (
                            <p className="mt-1 text-muted-foreground">
                                We will update this page when processing is
                                complete.
                            </p>
                        )}
                    </div>
                )}

                {photoPost.status === 'ready' && photoPost.mediaUrl && (
                    <article className="overflow-hidden rounded-lg border border-border bg-card">
                        <img
                            src={photoPost.mediaUrl}
                            alt={photoPost.caption || 'Shared photo'}
                            className="max-h-[70vh] w-full bg-muted object-contain"
                        />
                        {photoPost.caption && (
                            <p className="p-4 break-words whitespace-pre-wrap">
                                {photoPost.caption}
                            </p>
                        )}
                    </article>
                )}

                {photoPost.status === 'ready' && !photoPost.mediaUrl && (
                    <p
                        role="status"
                        className="rounded-md border border-border bg-card p-4 text-sm"
                    >
                        This photo is ready, but it could not be loaded. Refresh
                        the page to try again.
                    </p>
                )}

                {photoPost.status === 'failed' && (
                    <section
                        role="status"
                        className="space-y-3 rounded-md border border-border bg-card p-4"
                    >
                        <h2 className="font-medium">Photo unavailable</h2>
                        <p className="text-sm text-muted-foreground">
                            {photoPost.failure?.message ??
                                'This photo could not be prepared.'}
                        </p>
                    </section>
                )}

                {photoPost.status === 'cancelled' && (
                    <p
                        role="status"
                        className="rounded-md border border-border bg-card p-4 text-sm"
                    >
                        {statusCopy.cancelled}
                    </p>
                )}

                {photoPost.canCancel && (
                    <div className="space-y-2">
                        {cancelError && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                Cancellation could not be confirmed. Try again.
                            </p>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={canceling}
                            onClick={requestCancel}
                        >
                            {canceling ? 'Cancelling…' : 'Cancel post'}
                        </Button>
                    </div>
                )}

                {retryKey && (
                    <section className="space-y-4 rounded-lg border border-border bg-card p-4 sm:p-6">
                        <div className="space-y-1">
                            <h2 className="font-medium">Retry this photo</h2>
                            <p className="text-sm text-muted-foreground">
                                Select the same original photo and keep the
                                caption unchanged. To use different content,
                                start a new post.
                            </p>
                        </div>
                        <PhotoPostComposer
                            limits={limits}
                            decoderAvailable
                            retryKey={retryKey}
                            initialCaption={photoPost.caption ?? ''}
                        />
                    </section>
                )}

                {(photoPost.status === 'failed' ||
                    photoPost.status === 'cancelled') &&
                    !retryAvailable && (
                        <p
                            role="status"
                            className="rounded-md border border-border bg-card p-4 text-sm text-muted-foreground"
                        >
                            Retry is not available for this post right now. You
                            can start a new post.
                        </p>
                    )}

                {(photoPost.status === 'failed' ||
                    photoPost.status === 'cancelled') && (
                    <Button asChild variant="outline" className="min-h-11">
                        <Link href={create()} prefetch={false}>
                            Start a new post
                        </Link>
                    </Button>
                )}
            </div>
        </>
    );
}
