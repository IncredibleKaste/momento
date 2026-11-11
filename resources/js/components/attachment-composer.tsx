import { router, useHttp } from '@inertiajs/react';
import { Paperclip, Send, X } from 'lucide-react';
import { ChangeEvent, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import messagesRoutes from '@/routes/messages';

export type AttachmentCapabilities = {
    images: boolean;
    video: boolean;
    maxFiles: number;
    imageMaxBytes: number;
    videoMaxBytes: number;
    maxVideoSeconds: number;
    quotaBytes: number;
};

export type AttachmentSendResult = {
    nonce: string;
    status: 'processing' | 'sent' | 'failed';
    messageId: number | null;
    error: string | null;
};

export type PendingAttachmentSend = AttachmentSendResult | null;

export function classifyAttachmentHttpFailure(status: number): {
    attemptStatus: 'failed' | 'unknown';
    message: string;
} {
    if (status === 409) {
        return {
            attemptStatus: 'failed',
            message:
                'This upload key belongs to different content. Your draft is still here; retry creates a fresh upload key.',
        };
    }

    if (status === 413) {
        return {
            attemptStatus: 'failed',
            message:
                'The upload was too large for the server. Your files are still selected; choose smaller files.',
        };
    }

    if (status === 401) {
        return {
            attemptStatus: 'failed',
            message:
                'Your sign-in expired. Copy your message and selected files, then sign in again before retrying.',
        };
    }

    if (status === 419) {
        return {
            attemptStatus: 'failed',
            message:
                'Your session needs refreshing. Copy your message before refreshing.',
        };
    }

    if (status === 429) {
        return {
            attemptStatus: 'failed',
            message: 'Too many upload requests. Wait a minute, then retry.',
        };
    }

    if (status === 403 || status === 404) {
        return {
            attemptStatus: 'failed',
            message:
                'This conversation no longer allows attachments. Your message and files remain here.',
        };
    }

    return {
        attemptStatus: 'unknown',
        message:
            'The server did not confirm whether this upload was accepted. Check its status before trying again.',
    };
}

type UploadAttempt = {
    nonce: string;
    status: 'uploading' | 'processing' | 'unknown' | 'failed';
    error: string | null;
};

type UploadPayload = {
    body: string;
    nonce: string;
    attachments: File[];
};

type Props = {
    conversationId: number;
    body: string;
    capabilities: AttachmentCapabilities;
    pendingAttachmentSend: PendingAttachmentSend;
    onSent: () => void;
    onFlowChange: (state: { hasFiles: boolean; pending: boolean }) => void;
};

const automaticStatusChecks = 20;
const statusCheckIntervalMs = 3_000;

function formatBytes(bytes: number) {
    if (bytes >= 1_000_000_000) {
        return `${(bytes / 1_000_000_000).toFixed(1)} GB`;
    }

    if (bytes < 1_000_000) {
        return `${Math.max(1, Math.round(bytes / 1_000))} KB`;
    }

    return `${(bytes / 1_000_000).toFixed(1)} MB`;
}

function fileKind(file: File): 'image' | 'video' | null {
    const type = file.type.toLowerCase();
    const name = file.name.toLowerCase();
    const extension = /\.(jpe?g|png|webp|heic|heif)$/.test(name);

    if (
        [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
        ].includes(type) ||
        ((!type || type === 'application/octet-stream') && extension)
    ) {
        return 'image';
    }

    if (
        type === 'video/mp4' ||
        ((!type || type === 'application/octet-stream') &&
            name.endsWith('.mp4'))
    ) {
        return 'video';
    }

    return null;
}

function errorMessages(errors: Record<string, unknown>) {
    return Object.values(errors)
        .flatMap((value) => (Array.isArray(value) ? value : [value]))
        .filter((value): value is string => typeof value === 'string')
        .join(' ');
}

export default function AttachmentComposer({
    conversationId,
    body,
    capabilities,
    pendingAttachmentSend,
    onSent,
    onFlowChange,
}: Props) {
    const fileInput = useRef<HTMLInputElement>(null);
    const pollTimer = useRef<number | null>(null);
    const automaticChecks = useRef(0);
    const activeAttempt = useRef<UploadAttempt | null>(null);
    const mounted = useRef(true);
    const statusRequestCancel = useRef<() => void>(() => {});
    const handledSentNonces = useRef(new Set<string>());
    const [files, setFiles] = useState<File[]>([]);
    const [selectionError, setSelectionError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState<UploadAttempt | null>(null);
    const [checking, setChecking] = useState(false);
    const [automaticChecksPaused, setAutomaticChecksPaused] = useState(false);
    const [statusError, setStatusError] = useState<string | null>(null);
    const upload = useHttp<UploadPayload, AttachmentSendResult>({
        body: '',
        nonce: '',
        attachments: [],
    });
    const statusRequest = useHttp<Record<string, never>, AttachmentSendResult>(
        {},
    );

    const availableAcceptTypes = [
        ...(capabilities.images
            ? ['.jpg', '.jpeg', '.png', '.webp', '.heic', '.heif']
            : []),
        ...(capabilities.video ? ['.mp4'] : []),
    ];
    const isPending =
        attempt?.status === 'uploading' ||
        attempt?.status === 'processing' ||
        attempt?.status === 'unknown';

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    useEffect(() => {
        onFlowChange({ hasFiles: files.length > 0, pending: isPending });
    }, [files.length, isPending, onFlowChange]);

    useEffect(() => {
        statusRequestCancel.current = statusRequest.cancel;
    }, [statusRequest.cancel]);

    useEffect(() => {
        return () => {
            if (pollTimer.current !== null) {
                window.clearTimeout(pollTimer.current);
                pollTimer.current = null;
            }
            statusRequestCancel.current();
        };
    }, [conversationId]);

    function completeSent(currentAttempt: UploadAttempt) {
        if (
            !mounted.current ||
            handledSentNonces.current.has(currentAttempt.nonce)
        ) {
            return;
        }
        handledSentNonces.current.add(currentAttempt.nonce);
        activeAttempt.current = null;
        setAttempt(null);
        setFiles([]);
        setSelectionError(null);
        setStatusError(null);
        setAutomaticChecksPaused(false);
        upload.setData({ body: '', nonce: '', attachments: [] });
        upload.clearErrors();
        if (fileInput.current) fileInput.current.value = '';
        onSent();
        router.reload({ only: ['thread', 'conversations'] });
    }

    function updateFromResult(
        result: AttachmentSendResult,
        currentAttempt: UploadAttempt,
    ) {
        if (!mounted.current) return;
        if (result.nonce !== currentAttempt.nonce) {
            const next = {
                ...currentAttempt,
                status: 'unknown' as const,
                error: 'The server returned a different upload key. Check status before trying again.',
            };
            activeAttempt.current = next;
            setAttempt(next);
            return;
        }

        if (result.status === 'sent') {
            completeSent(currentAttempt);
            return;
        }

        if (result.status === 'failed') {
            const next = {
                ...currentAttempt,
                status: 'failed' as const,
                error:
                    result.error ??
                    'The server could not process these attachments.',
            };
            activeAttempt.current = next;
            setAttempt(next);
            setStatusError(null);
            return;
        }

        if (result.status !== 'processing') {
            const next = {
                ...currentAttempt,
                status: 'unknown' as const,
                error: 'The server returned an unrecognized upload status. Check again before retrying.',
            };
            activeAttempt.current = next;
            setAttempt(next);
            return;
        }

        const next = {
            ...currentAttempt,
            status: 'processing' as const,
            error: null,
        };
        activeAttempt.current = next;
        setAttempt(next);
        setStatusError(null);
    }

    function checkStatus(currentAttempt: UploadAttempt) {
        if (checking || statusRequest.processing) return;
        setChecking(true);
        setStatusError(null);
        void statusRequest
            .get(
                messagesRoutes.attachments.status.url({
                    conversation: conversationId,
                    nonce: currentAttempt.nonce,
                }),
                {
                    onSuccess: (result) => {
                        if (
                            activeAttempt.current?.nonce !==
                            currentAttempt.nonce
                        ) {
                            return;
                        }
                        updateFromResult(result, currentAttempt);
                    },
                    onError: (errors) => {
                        if (!mounted.current) return;
                        const next = {
                            ...currentAttempt,
                            status: 'unknown' as const,
                            error:
                                errorMessages(errors) ||
                                'The upload status could not be checked.',
                        };
                        activeAttempt.current = next;
                        setAttempt(next);
                        setStatusError(
                            'The upload status could not be checked. Keep this draft and try again.',
                        );
                    },
                    onNetworkError: () => {
                        if (!mounted.current) return;
                        const next = {
                            ...currentAttempt,
                            status: 'unknown' as const,
                            error: 'Connection interrupted while checking upload status.',
                        };
                        activeAttempt.current = next;
                        setAttempt(next);
                        setStatusError(
                            'Connection interrupted. The upload status is still unknown.',
                        );
                    },
                    onHttpException: (response) => {
                        if (!mounted.current) return;
                        const next = {
                            ...currentAttempt,
                            status: 'unknown' as const,
                            error: 'The upload status could not be confirmed.',
                        };
                        activeAttempt.current = next;
                        setAttempt(next);
                        setStatusError(
                            response.status === 401
                                ? 'Your sign-in expired. Copy your message, then sign in again and return to this conversation to check whether the upload completed.'
                                : response.status === 429
                                  ? 'Too many status checks. Wait a moment before checking again.'
                                  : response.status === 419
                                    ? 'Your session needs refreshing. Copy your message before refreshing.'
                                    : response.status === 404
                                      ? 'No status was found. Keep this draft and check again before sending another copy.'
                                      : 'The upload status could not be confirmed. Keep this draft and check again.',
                        );
                    },
                    onFinish: () => {
                        if (mounted.current) setChecking(false);
                    },
                    onCancel: () => {
                        if (mounted.current) setChecking(false);
                    },
                },
            )
            .catch(() => {
                // useHttp rethrows HTTP and network failures after calling the
                // callbacks above; the UI already records those outcomes.
            });
    }

    useEffect(() => {
        if (attempt?.status !== 'processing' || automaticChecksPaused) {
            return;
        }

        if (automaticChecks.current >= automaticStatusChecks) {
            setAutomaticChecksPaused(true);
            return;
        }

        const currentAttempt = attempt;
        pollTimer.current = window.setTimeout(() => {
            automaticChecks.current += 1;
            checkStatus(currentAttempt);
        }, statusCheckIntervalMs);

        return () => {
            if (pollTimer.current !== null) {
                window.clearTimeout(pollTimer.current);
                pollTimer.current = null;
            }
        };
    }, [attempt, automaticChecksPaused]);

    useEffect(() => {
        if (!pendingAttachmentSend) return;
        if (activeAttempt.current?.nonce === pendingAttachmentSend.nonce)
            return;

        if (pendingAttachmentSend.status === 'sent') {
            completeSent({
                nonce: pendingAttachmentSend.nonce,
                status: 'processing',
                error: null,
            });
            return;
        }

        const recovered: UploadAttempt = {
            nonce: pendingAttachmentSend.nonce,
            status:
                pendingAttachmentSend.status === 'failed'
                    ? 'failed'
                    : 'processing',
            error: pendingAttachmentSend.error,
        };
        activeAttempt.current = recovered;
        setAttempt(recovered);
        automaticChecks.current = 0;
        setAutomaticChecksPaused(false);
        setFiles([]);
    }, [conversationId, pendingAttachmentSend]);

    function clearSelection() {
        setFiles([]);
        setSelectionError(null);
        upload.clearErrors();
        if (fileInput.current) fileInput.current.value = '';
    }

    function selectFiles(event: ChangeEvent<HTMLInputElement>) {
        const selected = Array.from(event.currentTarget.files ?? []);
        event.currentTarget.value = '';
        if (selected.length === 0) return;

        if (selected.length > capabilities.maxFiles) {
            setSelectionError(
                `Choose no more than ${capabilities.maxFiles} files.`,
            );
            return;
        }

        const invalid = selected.find((file) => {
            const kind = fileKind(file);
            if (!kind) return true;
            if (kind === 'image') {
                return (
                    !capabilities.images ||
                    file.size > capabilities.imageMaxBytes
                );
            }
            return (
                !capabilities.video || file.size > capabilities.videoMaxBytes
            );
        });

        if (invalid) {
            const kind = fileKind(invalid);
            setSelectionError(
                !kind
                    ? 'Choose JPEG, PNG, WebP, HEIC, HEIF, or MP4 files.'
                    : kind === 'image' && !capabilities.images
                      ? 'Image attachments are unavailable because the image decoder is unavailable.'
                      : kind === 'video' && !capabilities.video
                        ? 'MP4 attachments are unavailable because the video decoder is unavailable.'
                        : `Each ${kind} must be no larger than ${formatBytes(kind === 'image' ? capabilities.imageMaxBytes : capabilities.videoMaxBytes)}.`,
            );
            return;
        }

        setFiles(selected);
        setSelectionError(null);
        setStatusError(null);
        upload.clearErrors();
        if (attempt?.status === 'failed') {
            const next = { ...attempt, error: null };
            activeAttempt.current = next;
            setAttempt(next);
        }
    }

    function beginUpload() {
        if (
            files.length === 0 ||
            isPending ||
            files.length > capabilities.maxFiles ||
            (attempt && attempt.status !== 'failed')
        ) {
            return;
        }

        const nonce = crypto.randomUUID();
        const next: UploadAttempt = {
            nonce,
            status: 'uploading',
            error: null,
        };
        activeAttempt.current = next;
        automaticChecks.current = 0;
        setAutomaticChecksPaused(false);
        setStatusError(null);
        setAttempt(next);
        upload.clearErrors();
        upload.setData({ body, nonce, attachments: files });
        void upload
            .post(messagesRoutes.attachments.store.url(conversationId), {
                onSuccess: (result) => updateFromResult(result, next),
                onError: (errors) => {
                    if (!mounted.current) return;
                    const nextAttempt = {
                        ...next,
                        status: 'failed' as const,
                        error:
                            errorMessages(errors) ||
                            'The server rejected this attachment send.',
                    };
                    activeAttempt.current = nextAttempt;
                    setAttempt(nextAttempt);
                },
                onNetworkError: () => {
                    if (!mounted.current) return;
                    const nextAttempt = {
                        ...next,
                        status: 'unknown' as const,
                        error: 'The connection ended before the server confirmed the upload.',
                    };
                    activeAttempt.current = nextAttempt;
                    setAttempt(nextAttempt);
                },
                onHttpException: (response) => {
                    if (!mounted.current) return;
                    const failure = classifyAttachmentHttpFailure(
                        response.status,
                    );
                    const nextAttempt = {
                        ...next,
                        status: failure.attemptStatus,
                        error: failure.message,
                    };
                    activeAttempt.current = nextAttempt;
                    setAttempt(nextAttempt);
                },
            })
            .catch(() => {
                // useHttp rethrows HTTP and network failures after calling the
                // callbacks above; the UI already records those outcomes.
            });
    }

    const supportedKinds = [
        capabilities.images ? 'images' : null,
        capabilities.video ? 'MP4 video' : null,
    ].filter((value): value is string => value !== null);

    return (
        <section className="space-y-2" aria-label="Message attachments">
            {supportedKinds.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    Attachments are unavailable because the required media
                    decoder is unavailable.
                </p>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            multiple
                            accept={availableAcceptTypes.join(',')}
                            disabled={isPending || supportedKinds.length === 0}
                            aria-label="Choose message attachments"
                            aria-describedby={
                                selectionError
                                    ? 'attachment-limits attachment-error'
                                    : 'attachment-limits'
                            }
                            onChange={selectFiles}
                            className="sr-only"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={isPending || supportedKinds.length === 0}
                            onClick={() => fileInput.current?.click()}
                            className="min-h-11"
                        >
                            <Paperclip aria-hidden="true" />
                            {files.length > 0 ? 'Change files' : 'Attach files'}
                        </Button>
                        {files.length > 0 && !isPending && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={clearSelection}
                                className="min-h-11"
                            >
                                <X aria-hidden="true" />
                                Remove files
                            </Button>
                        )}
                        {files.length > 0 && !isPending && (
                            <Button
                                type="button"
                                onClick={beginUpload}
                                disabled={files.length === 0}
                                className="min-h-11"
                            >
                                <Send aria-hidden="true" />
                                {attempt?.status === 'failed'
                                    ? 'Retry attachments'
                                    : 'Send with attachments'}
                            </Button>
                        )}
                    </div>
                    {files.length > 0 && (
                        <ul className="space-y-1 text-xs text-muted-foreground">
                            {files.map((file) => (
                                <li
                                    key={`${file.name}:${file.size}:${file.lastModified}`}
                                >
                                    {file.name} · {formatBytes(file.size)}
                                </li>
                            ))}
                        </ul>
                    )}
                    <p
                        id="attachment-limits"
                        className="text-xs text-muted-foreground"
                    >
                        Up to {capabilities.maxFiles} files per message.
                        {capabilities.images &&
                            ` Images up to ${formatBytes(capabilities.imageMaxBytes)} each.`}
                        {!capabilities.images &&
                            ' Image attachments are unavailable because the image decoder is unavailable.'}
                        {capabilities.video &&
                            ` MP4 (H.264 or HEVC SDR, without HDR) up to ${formatBytes(capabilities.videoMaxBytes)} and ${capabilities.maxVideoSeconds} seconds.`}
                        {!capabilities.video &&
                            ' MP4 attachments are unavailable because the video decoder is unavailable.'}{' '}
                        Uploads count toward your{' '}
                        {formatBytes(capabilities.quotaBytes)} sender storage
                        limit.
                    </p>
                    {selectionError && (
                        <p
                            id="attachment-error"
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            {selectionError}
                        </p>
                    )}
                    {upload.progress && upload.processing && (
                        <div className="space-y-1" aria-live="polite">
                            <div className="flex justify-between text-xs text-muted-foreground">
                                <span>Uploading attachments</span>
                                <span>{upload.progress.percentage}%</span>
                            </div>
                            <div
                                role="progressbar"
                                aria-label="Attachment upload progress"
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={upload.progress.percentage}
                                className="h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    className="h-full bg-primary transition-[width]"
                                    style={{
                                        width: `${upload.progress.percentage}%`,
                                    }}
                                />
                            </div>
                        </div>
                    )}
                </>
            )}
            {attempt && (
                <div className="space-y-2 rounded-md border border-border bg-card p-3 text-sm">
                    {attempt.status === 'uploading' ? (
                        <p role="status" className="text-muted-foreground">
                            Uploading files. Do not submit this attachment send
                            again.
                        </p>
                    ) : attempt.status === 'processing' ? (
                        <p role="status" className="text-muted-foreground">
                            Files are processing. This is not a sent message
                            yet.
                            {automaticChecksPaused
                                ? ' Automatic checks paused; check status when ready.'
                                : ' We check every three seconds for up to one minute.'}
                        </p>
                    ) : attempt.status === 'unknown' ? (
                        <p role="alert" className="text-destructive">
                            {attempt.error ?? 'The upload status is unknown.'}{' '}
                            Keep this draft and check status before retrying.
                        </p>
                    ) : (
                        <p role="alert" className="text-destructive">
                            {attempt.error ?? 'The attachment send failed.'}
                            {files.length === 0
                                ? ' Select the files again to make a new attempt.'
                                : ' Your files and message remain here. Retry creates a new upload attempt.'}
                        </p>
                    )}
                    {(attempt.status === 'processing' ||
                        attempt.status === 'unknown') && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={checking || statusRequest.processing}
                            onClick={() => checkStatus(attempt)}
                            className="min-h-11"
                        >
                            {checking || statusRequest.processing
                                ? 'Checking…'
                                : 'Check status'}
                        </Button>
                    )}
                    {statusError && (
                        <p role="alert" className="text-xs text-destructive">
                            {statusError}
                        </p>
                    )}
                    {attempt.status === 'failed' &&
                        upload.errors &&
                        Object.keys(upload.errors).length > 0 && (
                            <ul className="list-inside list-disc text-xs text-destructive">
                                {Object.values(upload.errors).map(
                                    (error, index) => (
                                        <li key={index}>{String(error)}</li>
                                    ),
                                )}
                            </ul>
                        )}
                </div>
            )}
        </section>
    );
}
