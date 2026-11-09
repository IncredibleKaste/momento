import { router } from '@inertiajs/react';
import { Bookmark, BookmarkCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    postId: number;
    saved: boolean;
    revision: number;
};

export default function BookmarkButton(props: Props) {
    return <BookmarkButtonState key={props.postId} {...props} />;
}

function BookmarkButtonState({ postId, saved, revision }: Props) {
    const [acknowledgedSaved, setAcknowledgedSaved] = useState(saved);
    const [currentRevision, setCurrentRevision] = useState(revision);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const requestLock = useRef(false);
    const pending = useRef<{
        saved: boolean;
        revision: number;
        requestId: string;
    } | null>(null);

    useEffect(() => {
        if (requestLock.current) return;
        setAcknowledgedSaved(saved);
        setCurrentRevision(revision);
        pending.current = null;
    }, [postId, saved, revision]);

    function toggle() {
        if (requestLock.current) return;

        const nextSaved = !acknowledgedSaved;
        const request =
            pending.current?.saved === nextSaved
                ? pending.current
                : {
                      saved: nextSaved,
                      revision: currentRevision,
                      requestId: crypto.randomUUID(),
                  };
        pending.current = request;
        requestLock.current = true;
        setBusy(true);
        setFailed(false);

        router.post(
            `/saved/${postId}`,
            {
                saved: request.saved,
                revision: request.revision,
                request_id: request.requestId,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAcknowledgedSaved(request.saved);
                    setCurrentRevision(request.revision + 1);
                    pending.current = null;
                },
                onError: () => setFailed(true),
                onNetworkError: () => setFailed(true),
                onHttpException: () => setFailed(true),
                onFinish: () => {
                    requestLock.current = false;
                    setBusy(false);
                },
            },
        );
    }

    return (
        <span className="inline-flex flex-col items-center gap-1">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                disabled={busy}
                aria-label={
                    acknowledgedSaved ? 'Remove saved photo' : 'Save photo'
                }
                aria-pressed={acknowledgedSaved}
                onClick={toggle}
                className="size-11"
            >
                {acknowledgedSaved ? (
                    <BookmarkCheck aria-hidden="true" />
                ) : (
                    <Bookmark aria-hidden="true" />
                )}
            </Button>
            {failed && (
                <span role="status" className="text-xs text-destructive">
                    Could not confirm this change. Retry or refresh.
                </span>
            )}
        </span>
    );
}
