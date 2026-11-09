import { useForm } from '@inertiajs/react';
import { ArrowUpFromLine, ImagePlus } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/photo-posts';
import type { PhotoPostLimits } from './types';
import {
    attemptSignature,
    captionCharacterCount,
    captionExceedsLimit,
    formatBytes,
    newPhotoPostAttempt,
    photoPostConflictMessage,
    recordPhotoPostAttempt,
    requiresNewAttempt,
    uploadRequestError,
} from './types';

type Props = {
    limits: PhotoPostLimits;
    decoderAvailable: boolean;
    retryKey?: string;
    initialCaption?: string;
};

export default function PhotoPostComposer({
    limits,
    decoderAvailable,
    retryKey,
    initialCaption = '',
}: Props) {
    const [attempt, setAttempt] = useState(() =>
        newPhotoPostAttempt(retryKey ?? crypto.randomUUID()),
    );
    const form = useForm({
        photo: null as File | null,
        caption: initialCaption,
        idempotency_key: attempt.key,
    });
    const [requestError, setRequestError] = useState<string | null>(null);
    const [serverConflict, setServerConflict] = useState(false);
    const errorSummary = useRef<HTMLDivElement>(null);
    const currentSignature = useMemo(
        () => attemptSignature(form.data.photo, form.data.caption),
        [form.data.photo, form.data.caption],
    );
    const needsNewAttempt = requiresNewAttempt(
        attempt,
        currentSignature,
        serverConflict,
    );
    const isRetry =
        retryKey !== undefined && form.data.idempotency_key === retryKey;
    const errors = form.errors;
    const fileOverLimit =
        form.data.photo !== null && form.data.photo.size > limits.maxInputBytes;
    const captionOverLimit = captionExceedsLimit(
        form.data.caption,
        limits.maxCaptionCharacters,
    );
    const photoError =
        errors.photo ??
        (fileOverLimit
            ? `Choose a photo no larger than ${formatBytes(limits.maxInputBytes)}.`
            : undefined);
    const captionError =
        errors.caption ??
        (captionOverLimit
            ? `Keep the caption to ${limits.maxCaptionCharacters} characters or fewer.`
            : undefined);
    const hasFieldErrors =
        Object.keys(errors).length > 0 || fileOverLimit || captionOverLimit;

    const focusErrorSummary = useCallback(() => {
        requestAnimationFrame(() => errorSummary.current?.focus());
    }, []);

    useEffect(() => {
        if (hasFieldErrors || requestError) focusErrorSummary();
    }, [focusErrorSummary, hasFieldErrors, requestError]);

    function beginNewAttempt() {
        const nextAttempt = newPhotoPostAttempt(crypto.randomUUID());
        setAttempt(nextAttempt);
        form.setData('idempotency_key', nextAttempt.key);
        form.clearErrors();
        setRequestError(null);
        setServerConflict(false);
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (
            form.processing ||
            needsNewAttempt ||
            !decoderAvailable ||
            form.data.photo === null ||
            fileOverLimit ||
            captionOverLimit
        )
            return;

        setRequestError(null);
        setAttempt(recordPhotoPostAttempt(attempt, currentSignature));
        form.post(store.url(), {
            preserveScroll: true,
            onError: (errors) => {
                if (errors.idempotency_key) {
                    setServerConflict(true);
                    setRequestError(photoPostConflictMessage);
                }
                focusErrorSummary();
            },
            onNetworkError: () => {
                setRequestError(
                    'The upload could not be confirmed. Retry with the same photo and caption, or start a new post if either has changed.',
                );
                focusErrorSummary();
            },
            onHttpException: (response) => {
                if (response.status === 409) setServerConflict(true);
                setRequestError(uploadRequestError(response.status));
                focusErrorSummary();
            },
        });
    }

    const keyError = errors.idempotency_key;
    const hasErrors = hasFieldErrors || requestError !== null;

    return (
        <section className="max-w-2xl space-y-6">
            {!decoderAvailable && (
                <p
                    role="status"
                    className="rounded-md border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
                >
                    Adding photos is temporarily unavailable. Please try again
                    later.
                </p>
            )}

            <form onSubmit={submit} className="space-y-6">
                {hasErrors && (
                    <div
                        ref={errorSummary}
                        tabIndex={-1}
                        role="alert"
                        aria-labelledby="photo-post-error-title"
                        className="rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <h2
                            id="photo-post-error-title"
                            className="font-medium text-foreground"
                        >
                            There was a problem with this post
                        </h2>
                        {requestError && (
                            <p className="mt-1 text-muted-foreground">
                                {requestError}
                            </p>
                        )}
                        {(photoError || captionError || keyError) && (
                            <ul className="mt-2 list-inside list-disc space-y-1">
                                {photoError && (
                                    <li>
                                        <a className="underline" href="#photo">
                                            {photoError}
                                        </a>
                                    </li>
                                )}
                                {captionError && (
                                    <li>
                                        <a
                                            className="underline"
                                            href="#caption"
                                        >
                                            {captionError}
                                        </a>
                                    </li>
                                )}
                                {keyError && !requestError && (
                                    <li>{keyError}</li>
                                )}
                            </ul>
                        )}
                    </div>
                )}

                {needsNewAttempt && (
                    <div
                        role="status"
                        className="rounded-md border border-border bg-muted/40 p-4 text-sm"
                    >
                        {serverConflict
                            ? 'The server found different content for this upload key. Start a new post to submit this selected photo and caption.'
                            : 'This photo or caption differs from the last attempt. Start a new post before submitting these changes.'}
                    </div>
                )}

                <fieldset
                    disabled={!decoderAvailable || form.processing}
                    className="space-y-6 disabled:opacity-70"
                >
                    <div className="space-y-2">
                        <label htmlFor="photo" className="text-sm font-medium">
                            Photo
                        </label>
                        <Input
                            id="photo"
                            name="photo"
                            type="file"
                            accept={limits.accept.join(',')}
                            required
                            aria-invalid={Boolean(photoError)}
                            aria-describedby="photo-help photo-error"
                            onChange={(event) => {
                                form.setData(
                                    'photo',
                                    event.currentTarget.files?.[0] ?? null,
                                );
                                form.clearErrors('photo');
                            }}
                            className="min-h-11 cursor-pointer py-2 file:mr-3 file:cursor-pointer"
                        />
                        <p
                            id="photo-help"
                            className="text-sm text-muted-foreground"
                        >
                            Choose one photo up to{' '}
                            {formatBytes(limits.maxInputBytes)} and{' '}
                            {(limits.maxPixels / 1_000_000).toLocaleString()}{' '}
                            megapixels. Image dimensions are checked after
                            upload. A preview is not used to decide whether a
                            photo can be processed.
                        </p>
                        {form.data.photo && (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <ImagePlus
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                Photo selected ·{' '}
                                {formatBytes(form.data.photo.size)}
                            </p>
                        )}
                        <InputError
                            id="photo-error"
                            message={photoError}
                            role="alert"
                        />
                    </div>

                    <div className="space-y-2">
                        <label
                            htmlFor="caption"
                            className="text-sm font-medium"
                        >
                            Caption{' '}
                            <span className="text-muted-foreground">
                                (optional)
                            </span>
                        </label>
                        <textarea
                            id="caption"
                            name="caption"
                            rows={4}
                            value={form.data.caption}
                            aria-invalid={Boolean(captionError)}
                            aria-describedby="caption-help caption-error"
                            onChange={(event) => {
                                form.setData('caption', event.target.value);
                                form.clearErrors('caption');
                            }}
                            className="min-h-28 w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        />
                        <div className="flex flex-wrap justify-between gap-2 text-sm text-muted-foreground">
                            <p id="caption-help">
                                Up to {limits.maxCaptionCharacters} characters.
                            </p>
                            <p aria-live="polite">
                                {captionCharacterCount(form.data.caption)}/
                                {limits.maxCaptionCharacters}
                            </p>
                        </div>
                        <InputError
                            id="caption-error"
                            message={captionError}
                            role="alert"
                        />
                    </div>
                </fieldset>

                {form.progress && form.processing && (
                    <div className="space-y-2" aria-live="polite">
                        <div className="flex justify-between text-sm">
                            <span>Uploading photo</span>
                            <span>{form.progress.percentage}%</span>
                        </div>
                        <div
                            role="progressbar"
                            aria-label="Photo upload progress"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={form.progress.percentage}
                            className="h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className="h-full bg-primary transition-[width]"
                                style={{
                                    width: `${form.progress.percentage}%`,
                                }}
                            />
                        </div>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        disabled={
                            !decoderAvailable ||
                            form.processing ||
                            !form.data.photo ||
                            fileOverLimit ||
                            captionOverLimit ||
                            needsNewAttempt
                        }
                        className="min-h-11"
                    >
                        <ArrowUpFromLine aria-hidden="true" />
                        {form.processing
                            ? 'Uploading…'
                            : isRetry
                              ? 'Retry photo'
                              : 'Share photo'}
                    </Button>
                    {needsNewAttempt && (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={beginNewAttempt}
                        >
                            Start a new post
                        </Button>
                    )}
                </div>
            </form>
        </section>
    );
}
