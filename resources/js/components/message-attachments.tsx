import { useState } from 'react';

export type MessageAttachment = {
    kind: 'image' | 'video';
    url: string | null;
    width: number;
    height: number;
    bytes: number;
    durationSeconds: number | null;
};

function formatBytes(bytes: number) {
    if (bytes < 1_000_000) {
        return `${Math.max(1, Math.round(bytes / 1_000))} KB`;
    }

    return `${(bytes / 1_000_000).toFixed(1)} MB`;
}

function AttachmentMedia({
    attachment,
    authorName,
}: {
    attachment: MessageAttachment;
    authorName: string;
}) {
    const [failed, setFailed] = useState(false);
    const caption =
        attachment.kind === 'image'
            ? `Image from ${authorName}`
            : `Video from ${authorName}`;

    if (!attachment.url || failed) {
        return (
            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                {attachment.kind === 'image'
                    ? 'Image unavailable.'
                    : 'Video unavailable.'}
            </p>
        );
    }

    if (attachment.kind === 'image') {
        return (
            <figure className="max-w-full space-y-1">
                <img
                    src={attachment.url}
                    alt={caption}
                    width={attachment.width}
                    height={attachment.height}
                    loading="lazy"
                    decoding="async"
                    onError={() => setFailed(true)}
                    className="max-h-[min(70vh,36rem)] max-w-full rounded-md object-contain"
                />
                <figcaption className="text-xs text-muted-foreground">
                    Image · {formatBytes(attachment.bytes)}
                </figcaption>
            </figure>
        );
    }

    const duration = attachment.durationSeconds;

    return (
        <figure className="max-w-full space-y-1">
            <video
                src={attachment.url}
                controls
                preload="metadata"
                playsInline
                width={attachment.width}
                height={attachment.height}
                onError={() => setFailed(true)}
                aria-label={caption}
                className="max-h-[min(70vh,36rem)] max-w-full rounded-md bg-black object-contain"
            >
                MP4 video playback is unavailable in this browser.
            </video>
            <figcaption className="text-xs text-muted-foreground">
                Video
                {duration !== null && ` · ${Math.round(duration)} sec`}
                {' · '}
                {formatBytes(attachment.bytes)}
            </figcaption>
        </figure>
    );
}

export default function MessageAttachments({
    attachments,
    hidden,
    authorName,
}: {
    attachments: MessageAttachment[];
    hidden: boolean;
    authorName: string;
}) {
    if (attachments.length === 0 && !hidden) return null;

    return (
        <div className="mt-2 grid gap-2">
            {hidden ? (
                <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                    Attachments are unavailable under current messaging
                    permissions.
                </p>
            ) : (
                attachments.map((attachment) => (
                    <AttachmentMedia
                        key={`${attachment.kind}:${attachment.url ?? 'hidden'}`}
                        attachment={attachment}
                        authorName={authorName}
                    />
                ))
            )}
        </div>
    );
}
