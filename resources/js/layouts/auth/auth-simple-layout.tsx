import { Link } from '@inertiajs/react';
import MomentoWordmark from '@/components/momento-wordmark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
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
