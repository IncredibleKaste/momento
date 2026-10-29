import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

export default function NotFound() {
    const { auth } = usePage().props;

    return (
        <main className="mx-auto flex min-h-svh max-w-xl flex-col items-start justify-center gap-5 px-6">
            <Head title="Page not found" />
            <p className="font-serif text-2xl">Momento</p>
            <h1 className="font-serif text-3xl">Page not found</h1>
            <p className="text-muted-foreground">
                That page could not be found.
            </p>
            <Button asChild>
                <Link href={auth.user ? dashboard() : login()}>
                    {auth.user ? 'Go home' : 'Sign in'}
                </Link>
            </Button>
        </main>
    );
}
