import { useEffect, useRef, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { Button } from './ui/button';
import photoLikes from '../routes/photo-likes';

type LikeState = { liked: boolean; revision: number };
type LikeIntent = LikeState & { request_id: string };

type Props = LikeState & { postId: number };

function isLikeState(value: unknown): value is LikeState {
    if (typeof value !== 'object' || value === null) return false;

    const state = value as Partial<LikeState>;
    return (
        typeof state.liked === 'boolean' &&
        Number.isSafeInteger(state.revision) &&
        (state.revision ?? -1) >= 0
    );
}

export default function PhotoLikeButton(props: Props) {
    return <PhotoLikeButtonState key={props.postId} {...props} />;
}

function PhotoLikeButtonState({ postId, liked, revision }: Props) {
    const [state, setState] = useState<LikeState>({ liked, revision });
    const [pending, setPending] = useState<LikeIntent | null>(null);
    const [error, setError] = useState('');
    const previousProps = useRef({ liked, revision });
    const endpoint = photoLikes.store({ photoPost: postId });
    const request = useHttp<LikeIntent, LikeState>({
        liked: state.liked,
        revision: state.revision,
        request_id: '',
    });

    useEffect(() => {
        const changed =
            previousProps.current.liked !== liked ||
            previousProps.current.revision !== revision;
        previousProps.current = { liked, revision };
        if (changed && !pending) setState({ liked, revision });
    }, [liked, revision, pending]);

    function send(intent: LikeIntent) {
        setPending(intent);
        setError('');
        request.setData(intent);

        void request
            .post(endpoint.url, {
                onSuccess: (next) => {
                    if (!isLikeState(next)) {
                        setError(
                            'Momento could not confirm this change. Retry to check its status.',
                        );
                        return;
                    }

                    setState(next);
                    setPending(null);
                },
                onError: () =>
                    setError(
                        'Momento could not save this change. Retry safely.',
                    ),
                onNetworkError: () =>
                    setError('Connection interrupted. Retry safely.'),
                onHttpException: (response) => {
                    if (response.status === 409) {
                        try {
                            const current: unknown = JSON.parse(response.data);
                            if (isLikeState(current)) setState(current);
                        } catch {
                            // Keep the last acknowledged state if the response is malformed.
                        }
                        setPending(null);
                        setError(
                            'This like changed elsewhere. Select again to continue.',
                        );
                    } else if (response.status === 419) {
                        setError(
                            'Your session needs refreshing. Retry after refreshing this page.',
                        );
                    } else if (response.status === 429) {
                        setError(
                            'Too many changes. Wait a moment, then retry.',
                        );
                    } else if (response.status === 404) {
                        setPending(null);
                        setError('This photo is no longer available.');
                    } else {
                        setError(
                            'Momento could not save this change. Retry safely.',
                        );
                    }
                },
            })
            .catch(() => {
                // Keep the same intent and request ID so a retry is idempotent.
                setError(
                    (current) =>
                        current || 'Connection interrupted. Retry safely.',
                );
            });
    }

    function toggle() {
        if (pending || request.processing) return;

        send({
            liked: !state.liked,
            revision: state.revision,
            request_id: crypto.randomUUID(),
        });
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                variant="ghost"
                aria-label={state.liked ? 'Unlike photo' : 'Like photo'}
                aria-pressed={state.liked}
                disabled={request.processing || pending !== null}
                onClick={toggle}
                className="min-h-11 min-w-11 px-3"
            >
                <Heart
                    aria-hidden="true"
                    className={state.liked ? 'fill-current text-rose-600' : ''}
                />
                <span>{state.liked ? 'Liked' : 'Like'}</span>
            </Button>
            {pending && error && !request.processing && (
                <Button
                    type="button"
                    variant="outline"
                    aria-label="Retry like change for this photo"
                    className="min-h-11"
                    onClick={() => send(pending)}
                >
                    Retry
                </Button>
            )}
            <span className="sr-only" aria-live="polite">
                {request.processing ? 'Saving like' : ''}
            </span>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
