import { Head, Link, usePage } from '@inertiajs/react';
import { Moon, RotateCcw, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import MomentoWordmark from '@/components/momento-wordmark';
import { useAppearance } from '@/hooks/use-appearance';
import { dashboard, home, login, register } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const poster = useRef<HTMLElement>(null);
    const animations = useRef<Animation[]>([]);

    const finishIntro = useCallback(() => {
        animations.current.forEach((animation) => animation.cancel());
        animations.current = [];
    }, []);

    const startIntro = useCallback(() => {
        finishIntro();

        if (
            !poster.current ||
            document.hidden ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
            typeof HTMLElement.prototype.animate !== 'function'
        ) {
            return;
        }

        poster.current
            .querySelectorAll<HTMLElement>('.welcome-ribbon span')
            .forEach((strip, index) => {
                animations.current.push(
                    strip.animate(
                        [
                            { transform: 'translateX(-100%)' },
                            { transform: getComputedStyle(strip).transform },
                        ],
                        {
                            duration: 1150,
                            delay: index * 45,
                            easing: 'cubic-bezier(.76, 0, .24, 1)',
                            fill: 'backwards',
                        },
                    ),
                );
            });

        poster.current
            .querySelectorAll<HTMLElement>('.welcome-copy > *')
            .forEach((part, index) => {
                animations.current.push(
                    part.animate(
                        [
                            { opacity: 0.8, transform: 'translateY(6px)' },
                            { opacity: 1, transform: 'translateY(0)' },
                        ],
                        {
                            duration: 650,
                            delay: 160 + index * 65,
                            easing: 'cubic-bezier(.16, 1, .3, 1)',
                            fill: 'backwards',
                        },
                    ),
                );
            });
    }, [finishIntro]);

    useEffect(() => {
        const reducedMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        );
        const currentPoster = poster.current;
        const onVisibilityChange = () => {
            if (document.hidden) {
                finishIntro();
            }
        };

        startIntro();
        reducedMotion.addEventListener('change', finishIntro);
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('resize', finishIntro);
        currentPoster?.addEventListener('focusin', finishIntro);

        return () => {
            finishIntro();
            reducedMotion.removeEventListener('change', finishIntro);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
            window.removeEventListener('resize', finishIntro);
            currentPoster?.removeEventListener('focusin', finishIntro);
        };
    }, [finishIntro, startIntro]);

    return (
        <>
            <Head title="Life moves. Keep a little." />
            <div className="welcome-page">
                <a className="welcome-skip" href="#welcome-main">
                    Skip to content
                </a>

                <header className="welcome-header">
                    <Link
                        href={home()}
                        className="welcome-brand"
                        aria-label="Momento home"
                    >
                        <MomentoWordmark />
                    </Link>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11"
                        aria-label={`Switch to ${resolvedAppearance === 'dark' ? 'light' : 'dark'} appearance`}
                        onClick={() =>
                            updateAppearance(
                                resolvedAppearance === 'dark'
                                    ? 'light'
                                    : 'dark',
                            )
                        }
                    >
                        {resolvedAppearance === 'dark' ? (
                            <Sun aria-hidden="true" />
                        ) : (
                            <Moon aria-hidden="true" />
                        )}
                    </Button>
                </header>

                <main
                    id="welcome-main"
                    ref={poster}
                    className="welcome-poster"
                    tabIndex={-1}
                >
                    <div className="welcome-copy">
                        <h1>
                            Life moves.
                            <br />
                            Keep a little.
                        </h1>
                        <p>Your photos. Your people. Your everyday.</p>
                        <div className="welcome-actions">
                            <Button asChild size="lg" className="min-h-12 px-7">
                                <Link href={auth.user ? dashboard() : login()}>
                                    {auth.user ? 'Open Momento' : 'Sign in'}
                                </Link>
                            </Button>
                            {!auth.user && (
                                <Link
                                    href={register()}
                                    className="welcome-register"
                                >
                                    Create an account
                                </Link>
                            )}
                        </div>
                    </div>
                    <div className="welcome-ribbon" aria-hidden="true">
                        {[0, 1, 2, 3, 4].map((band) => (
                            <span key={band} />
                        ))}
                    </div>
                </main>

                <footer className="welcome-footer">
                    <small>© {new Date().getFullYear()} Momento</small>
                    <Button
                        type="button"
                        variant="ghost"
                        className="welcome-replay min-h-11"
                        onClick={startIntro}
                    >
                        <RotateCcw
                            data-icon="inline-start"
                            aria-hidden="true"
                        />
                        Replay ribbon
                    </Button>
                </footer>
            </div>
        </>
    );
}
