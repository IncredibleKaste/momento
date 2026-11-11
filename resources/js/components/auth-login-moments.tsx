import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';

export type AuthLoginMoment = {
    src: string;
    alt: string;
};

export const authLoginMoments: AuthLoginMoment[] = [
    {
        src: '/images/auth-login/lake.jpg',
        alt: 'A swimmer in a quiet alpine lake',
    },
    {
        src: '/images/auth-login/dinner.jpg',
        alt: 'Friends gathered around a dinner table',
    },
    {
        src: '/images/auth-login/tram.jpg',
        alt: 'A vintage tram crossing a city street',
    },
    {
        src: '/images/auth-login/cabin.jpg',
        alt: 'A cabin among misty trees',
    },
    {
        src: '/images/auth-login/coffee.jpg',
        alt: 'Coffee and a notebook in morning light',
    },
];

export function readyAuthLoginMoments(
    results: readonly PromiseSettledResult<AuthLoginMoment>[],
): AuthLoginMoment[] {
    return results.flatMap((result) =>
        result.status === 'fulfilled' ? [result.value] : [],
    );
}

export function selectCurrentAuthLoginMoment(
    moments: readonly AuthLoginMoment[],
    currentSource: string,
): AuthLoginMoment | undefined {
    return moments.find((moment) => moment.src === currentSource) ?? moments[0];
}

export function canAnimateAuthLoginMoments({
    ready,
    slideCount,
    userPaused,
    reducedMotion,
    pageHidden,
}: {
    ready: boolean;
    slideCount: number;
    userPaused: boolean;
    reducedMotion: boolean;
    pageHidden: boolean;
}): boolean {
    return (
        canOfferAuthLoginMotionControl({ ready, slideCount, reducedMotion }) &&
        !userPaused &&
        !pageHidden
    );
}

export function canOfferAuthLoginMotionControl({
    ready,
    slideCount,
    reducedMotion,
}: {
    ready: boolean;
    slideCount: number;
    reducedMotion: boolean;
}): boolean {
    return ready && slideCount > 1 && !reducedMotion;
}

export function nextAuthLoginMomentIndex(
    currentIndex: number,
    slideCount: number,
): number {
    return slideCount > 1 ? (currentIndex + 1) % slideCount : 0;
}

async function decodeMoment(moment: AuthLoginMoment): Promise<AuthLoginMoment> {
    const image = new Image();
    image.src = moment.src;
    await image.decode();

    return moment;
}

export default function AuthLoginMoments() {
    const [moments, setMoments] = useState(authLoginMoments);
    const [failedSources, setFailedSources] = useState<string[]>([]);
    const [currentSource, setCurrentSource] = useState(authLoginMoments[0].src);
    const [ready, setReady] = useState(false);
    const [userPaused, setUserPaused] = useState(false);
    const [reducedMotion, setReducedMotion] = useState(true);
    const [pageHidden, setPageHidden] = useState(true);
    const availableMoments = moments.filter(
        (moment) => !failedSources.includes(moment.src),
    );
    const currentMoment = selectCurrentAuthLoginMoment(
        availableMoments,
        currentSource,
    );
    const currentIndex = currentMoment
        ? availableMoments.findIndex(
              (moment) => moment.src === currentMoment.src,
          )
        : 0;
    const motionEnabled = canAnimateAuthLoginMoments({
        ready,
        slideCount: availableMoments.length,
        userPaused,
        reducedMotion,
        pageHidden,
    });
    const canOfferMotionControl = canOfferAuthLoginMotionControl({
        ready,
        slideCount: availableMoments.length,
        reducedMotion,
    });

    useEffect(() => {
        let active = true;

        void Promise.allSettled(authLoginMoments.map(decodeMoment)).then(
            (results) => {
                if (!active) {
                    return;
                }

                const decodedMoments = readyAuthLoginMoments(results);
                setMoments(decodedMoments);
                setCurrentSource(decodedMoments[0]?.src ?? '');
                setReady(true);
            },
        );

        return () => {
            active = false;
        };
    }, []);

    useEffect(() => {
        const reducedMotionQuery = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        );
        const syncMotionPreferences = () => {
            setReducedMotion(reducedMotionQuery.matches);
            setPageHidden(document.hidden);
        };

        syncMotionPreferences();
        reducedMotionQuery.addEventListener('change', syncMotionPreferences);
        document.addEventListener('visibilitychange', syncMotionPreferences);

        return () => {
            reducedMotionQuery.removeEventListener(
                'change',
                syncMotionPreferences,
            );
            document.removeEventListener(
                'visibilitychange',
                syncMotionPreferences,
            );
        };
    }, []);

    useEffect(() => {
        if (!motionEnabled) {
            return;
        }

        const timer = window.setTimeout(() => {
            setCurrentSource(
                availableMoments[
                    nextAuthLoginMomentIndex(
                        currentIndex,
                        availableMoments.length,
                    )
                ]?.src ?? '',
            );
        }, 5200);

        return () => window.clearTimeout(timer);
    }, [availableMoments, currentIndex, motionEnabled]);

    const handleImageError = (source: string) => {
        setFailedSources((sources) => [...new Set([...sources, source])]);
    };

    return (
        <div className="auth-login-moments-wrap">
            <div
                className="auth-login-moments"
                data-motion-enabled={motionEnabled}
                role="img"
                aria-label={currentMoment?.alt ?? 'A photo story by Momento'}
            >
                {availableMoments.length === 0 ? (
                    <span className="auth-login-moments-fallback">Momento</span>
                ) : (
                    availableMoments.map((moment) => (
                        <figure
                            key={moment.src}
                            className="auth-login-moment"
                            data-current={moment.src === currentMoment?.src}
                            aria-hidden="true"
                        >
                            <img
                                src={moment.src}
                                alt=""
                                width="600"
                                height="700"
                                decoding="async"
                                className="auth-login-moment-photo"
                                onError={() => handleImageError(moment.src)}
                            />
                        </figure>
                    ))
                )}
                <div
                    className="auth-login-moments-spectrum"
                    aria-hidden="true"
                />
            </div>

            <div className="auth-login-moments-controls">
                <span>A little of life, in motion.</span>
                <Button
                    type="button"
                    variant="ghost"
                    className="auth-login-motion-toggle"
                    disabled={!canOfferMotionControl}
                    aria-pressed={canOfferMotionControl && userPaused}
                    onClick={() => setUserPaused((paused) => !paused)}
                >
                    {!canOfferMotionControl
                        ? 'Still preview'
                        : userPaused
                          ? 'Play motion'
                          : 'Pause motion'}
                </Button>
            </div>
        </div>
    );
}
