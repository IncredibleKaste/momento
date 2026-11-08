import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export default function Unsubscribe({ action }: { action: string }) {
    return (
        <>
            <Head title="Unsubscribe from product news" />

            <main className="mx-auto max-w-xl space-y-6 px-4 py-12">
                <header>
                    <h1 className="mb-0.5 text-base font-medium">
                        Unsubscribe from product news
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Confirm that you want to stop occasional Momento product
                        news. Account verification and recovery emails will
                        continue.
                    </p>
                </header>

                <Form action={action} method="post">
                    {({ processing }) => (
                        <Button disabled={processing} className="min-h-11">
                            {processing ? 'Unsubscribing…' : 'Unsubscribe'}
                        </Button>
                    )}
                </Form>
            </main>
        </>
    );
}
