export type PhotoPostLimits = {
    maxInputBytes: number;
    maxPixels: number;
    maxCaptionCharacters: number;
    accept: string[];
};

export type PhotoPostStatus =
    | 'staging'
    | 'queued'
    | 'processing'
    | 'ready'
    | 'failed'
    | 'cancel_requested'
    | 'cancelled';

export type PhotoPost = {
    id: number;
    status: PhotoPostStatus;
    caption: string | null;
    mediaUrl: string | null;
    canCancel: boolean;
    canRetry: boolean;
    retryKey: string | null;
    failure: { code: string; message: string } | null;
};

export const photoPostConflictMessage =
    'This upload key is already linked to different photo or caption details. Start a new post to submit changed content.';

export type PhotoPostAttempt = {
    key: string;
    submittedSignature: string | null;
};

export function newPhotoPostAttempt(key: string): PhotoPostAttempt {
    return { key, submittedSignature: null };
}

export function recordPhotoPostAttempt(
    attempt: PhotoPostAttempt,
    signature: string,
): PhotoPostAttempt {
    return { ...attempt, submittedSignature: signature };
}

export function attemptSignature(photo: File | null, caption: string) {
    return JSON.stringify([
        photo?.name ?? null,
        photo?.size ?? null,
        photo?.lastModified ?? null,
        photo?.type ?? null,
        caption,
    ]);
}

export function captionCharacterCount(caption: string) {
    return Array.from(caption).length;
}

export function captionExceedsLimit(caption: string, maximum: number) {
    return captionCharacterCount(caption) > maximum;
}

export function requiresNewAttempt(
    attempt: PhotoPostAttempt,
    currentSignature: string,
    serverConflict = false,
) {
    return (
        serverConflict ||
        (attempt.submittedSignature !== null &&
            attempt.submittedSignature !== currentSignature)
    );
}

export function isPhotoPostPolling(status: PhotoPostStatus) {
    return (
        status === 'staging' ||
        status === 'queued' ||
        status === 'processing' ||
        status === 'cancel_requested'
    );
}

export function canRetryPhotoPost(
    photoPost: PhotoPost,
): photoPost is PhotoPost & {
    status: 'failed' | 'cancelled';
    retryKey: string;
} {
    return (
        (photoPost.status === 'failed' || photoPost.status === 'cancelled') &&
        photoPost.canRetry &&
        photoPost.retryKey !== null
    );
}

export function canRequestPhotoPostCancel(
    photoPost: Pick<PhotoPost, 'canCancel'>,
    requestInFlight: boolean,
) {
    return photoPost.canCancel && !requestInFlight;
}

export function uploadRequestError(status: number) {
    return status === 409
        ? photoPostConflictMessage
        : 'The upload could not be completed. Try again later.';
}

export function formatBytes(bytes: number) {
    if (bytes < 1_000_000) return `${Math.ceil(bytes / 1_000)} KB`;

    return `${(bytes / 1_000_000).toFixed(1)} MB`;
}
