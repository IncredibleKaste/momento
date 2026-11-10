import { expect, it } from 'vite-plus/test';
import {
    attemptSignature,
    canRequestPhotoPostCancel,
    canRetryPhotoPost,
    captionCharacterCount,
    captionExceedsLimit,
    isPhotoPostPolling,
    newPhotoPostAttempt,
    photoPostConflictMessage,
    recordPhotoPostAttempt,
    requiresNewAttempt,
    uploadRequestError,
    type PhotoPost,
} from '../../resources/js/pages/photo-posts/types';

const samplePhoto = new File([new Uint8Array(250_000)], 'camera.heic', {
    lastModified: 123,
    type: 'image/heic',
});

function photoPost(overrides: Partial<PhotoPost> = {}): PhotoPost {
    return {
        id: 7,
        status: 'queued',
        caption: null,
        location: null,
        mediaUrl: null,
        canDelete: false,
        canCancel: false,
        canRetry: false,
        retryKey: null,
        failure: null,
        ...overrides,
    };
}

it('keeps the key for an unchanged retry and requires an explicit new attempt after edits', () => {
    const signature = attemptSignature(samplePhoto, 'At the lake');
    const firstAttempt = newPhotoPostAttempt('original-key');
    const submitted = recordPhotoPostAttempt(firstAttempt, signature);

    expect(submitted.key).toBe('original-key');
    expect(requiresNewAttempt(submitted, signature)).toBe(false);
    expect(
        requiresNewAttempt(
            submitted,
            attemptSignature(samplePhoto, 'Changed caption'),
        ),
    ).toBe(true);

    const explicitNewAttempt = newPhotoPostAttempt('new-key');

    expect(explicitNewAttempt).toEqual({
        key: 'new-key',
        submittedSignature: null,
    });
    expect(requiresNewAttempt(explicitNewAttempt, signature)).toBe(false);
});

it('changes the draft signature when the photo identity, caption, or location changes', () => {
    const original = attemptSignature(samplePhoto, 'At the lake');
    const anotherPhoto = new File(
        [new Uint8Array(samplePhoto.size + 1)],
        samplePhoto.name,
        { type: samplePhoto.type, lastModified: samplePhoto.lastModified },
    );

    expect(attemptSignature(samplePhoto, 'At the lake')).toBe(original);
    expect(attemptSignature(anotherPhoto, 'At the lake')).not.toBe(original);
    expect(attemptSignature(samplePhoto, 'Different caption')).not.toBe(
        original,
    );
    expect(attemptSignature(samplePhoto, 'At the lake', 'Zürich')).not.toBe(
        original,
    );
});

it('requires a fresh key after a server conflict even for a same-metadata file', () => {
    const identicalMetadataDifferentBytes = new File(
        [new Uint8Array(samplePhoto.size).fill(1)],
        samplePhoto.name,
        { type: samplePhoto.type, lastModified: samplePhoto.lastModified },
    );
    const signature = attemptSignature(samplePhoto, 'At the lake');
    const attempt = recordPhotoPostAttempt(
        newPhotoPostAttempt('conflicted-key'),
        signature,
    );

    expect(identicalMetadataDifferentBytes).not.toBe(samplePhoto);
    expect(identicalMetadataDifferentBytes.size).toBe(samplePhoto.size);
    expect(identicalMetadataDifferentBytes.name).toBe(samplePhoto.name);
    expect(identicalMetadataDifferentBytes.lastModified).toBe(
        samplePhoto.lastModified,
    );
    expect(identicalMetadataDifferentBytes.type).toBe(samplePhoto.type);
    expect(
        attemptSignature(identicalMetadataDifferentBytes, 'At the lake'),
    ).toBe(signature);
    expect(requiresNewAttempt(attempt, signature)).toBe(false);
    expect(requiresNewAttempt(attempt, signature, true)).toBe(true);

    const freshAttempt = newPhotoPostAttempt('fresh-key');

    expect(requiresNewAttempt(freshAttempt, signature)).toBe(false);
    expect(freshAttempt.key).toBe('fresh-key');
});

it('allows 1,000 astral characters and preserves over-limit captions for validation', () => {
    const validCaption = '😀'.repeat(1000);
    const overLimitCaption = `${validCaption}😀`;

    expect(captionCharacterCount(validCaption)).toBe(1000);
    expect(captionExceedsLimit(validCaption, 1000)).toBe(false);
    expect(captionCharacterCount(overLimitCaption)).toBe(1001);
    expect(captionExceedsLimit(overLimitCaption, 1000)).toBe(true);
    expect(JSON.parse(attemptSignature(samplePhoto, overLimitCaption))[4]).toBe(
        overLimitCaption,
    );
});

it('polls only statuses that can still change', () => {
    expect(isPhotoPostPolling('staging')).toBe(true);
    expect(isPhotoPostPolling('queued')).toBe(true);
    expect(isPhotoPostPolling('processing')).toBe(true);
    expect(isPhotoPostPolling('cancel_requested')).toBe(true);
    expect(isPhotoPostPolling('ready')).toBe(false);
    expect(isPhotoPostPolling('failed')).toBe(false);
    expect(isPhotoPostPolling('cancelled')).toBe(false);
});

it('offers a retry only when the server supplies an eligible retry key', () => {
    expect(
        canRetryPhotoPost(
            photoPost({ status: 'failed', canRetry: true, retryKey: 'retry' }),
        ),
    ).toBe(true);
    expect(
        canRetryPhotoPost(
            photoPost({
                status: 'cancelled',
                canRetry: true,
                retryKey: 'retry',
            }),
        ),
    ).toBe(true);
    expect(
        canRetryPhotoPost(
            photoPost({ status: 'failed', canRetry: true, retryKey: null }),
        ),
    ).toBe(false);
    expect(
        canRetryPhotoPost(
            photoPost({ status: 'failed', canRetry: false, retryKey: 'retry' }),
        ),
    ).toBe(false);
});

it('does not send cancellation when the server disallows it or a request is active', () => {
    expect(
        canRequestPhotoPostCancel(photoPost({ canCancel: true }), false),
    ).toBe(true);
    expect(
        canRequestPhotoPostCancel(photoPost({ canCancel: false }), false),
    ).toBe(false);
    expect(
        canRequestPhotoPostCancel(photoPost({ canCancel: true }), true),
    ).toBe(false);
});

it('maps HTTP failures to generic copy and hides response details', () => {
    expect(uploadRequestError(409)).toBe(photoPostConflictMessage);
    expect(uploadRequestError(500)).toBe(
        'The upload could not be completed. Try again later.',
    );
});
