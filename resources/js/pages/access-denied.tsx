import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

export default function AccessDenied() {
    return (
        <main className="mx-auto flex min-h-svh max-w-xl flex-col items-start justify-center gap-5 px-6">
            <Head title="Access unavailable" />
            <p className="font-serif text-2xl">Momento</p>
            <h1 className="font-serif text-3xl">Access unavailable</h1>
            <p className="text-muted-foreground">
                Your access is no longer available. Please sign in again or
                contact support.
            </p>
            <Button asChild>
                <Link href={login()}>Sign in</Link>
            </Button>
        </main>
    );
}
