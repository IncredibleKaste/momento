import { Head } from '@inertiajs/react';

export default function Unsubscribed() {
    return (
        <>
            <Head title="Unsubscribed" />

            <main className="mx-auto max-w-xl space-y-6 px-4 py-12">
                <header>
                    <h1 className="mb-0.5 text-base font-medium">
                        You are unsubscribed
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        You will no longer receive occasional Momento product
                        news. Account verification and recovery emails are
                        unaffected.
                    </p>
                </header>
            </main>
        </>
    );
}
