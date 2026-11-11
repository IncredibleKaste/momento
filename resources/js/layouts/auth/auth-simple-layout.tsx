import { Link, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import AuthLoginMoments from '@/components/auth-login-moments';
import MomentoWordmark from '@/components/momento-wordmark';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const isLogin = usePage().component === 'auth/login';
    const { resolvedAppearance, updateAppearance } = useAppearance();

    if (isLogin) {
        return (
            <div className="auth-page auth-login-page">
                <header className="auth-login-header">
                    <Link href={home()} aria-label="Momento home">
                        <MomentoWordmark className="h-10 w-[153px] sm:h-12 sm:w-44" />
                    </Link>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11 rounded-full border border-border"
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

                <main className="auth-login-main">
                    <section className="auth-login-content">
                        <div className="auth-login-intro">
                            <h1 className="font-serif">{title}</h1>
                            <p className="text-muted-foreground">
                                {description}
                            </p>
                        </div>
                        <div className="flex flex-col gap-6">{children}</div>
                    </section>

                    <section
                        className="auth-login-story"
                        aria-labelledby="auth-login-story-title"
                    >
                        <h2 id="auth-login-story-title" className="font-serif">
                            For the days
                            <br />
                            you want to keep.
                        </h2>
                        <AuthLoginMoments />
                    </section>
                </main>

                <footer className="auth-login-footer">
                    <span>© {new Date().getFullYear()} Momento</span>
                </footer>
            </div>
        );
    }

    return (
        <div className="auth-page flex min-h-svh flex-col items-center justify-center bg-background px-6 py-10 sm:px-8">
            <div className="w-full max-w-sm">
                <Link
                    href={home()}
                    className="mb-8 gap-3 font-serif text-3xl"
                    aria-label="Momento home"
                >
                    <MomentoWordmark />
                </Link>
                <main>
                    <div className="mb-8 space-y-3">
                        <h1 className="font-serif text-3xl leading-tight text-balance">
                            {title}
                        </h1>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="flex flex-col gap-6">{children}</div>
                </main>
            </div>
        </div>
    );
}
