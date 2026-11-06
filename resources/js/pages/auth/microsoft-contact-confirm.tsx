import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

export default function MicrosoftContactConfirm({
    email,
    confirmUrl,
}: {
    email: string;
    confirmUrl: string;
}) {
    return (
        <>
            <Head title="Confirm contact email" />
            <p className="mb-6 text-sm text-muted-foreground">
                Confirm <span className="break-all">{email}</span> to create
                your Microsoft-only Momento account. This confirmation must be
                completed in the browser session that started Microsoft sign-in.
            </p>
            <Form
                action={confirmUrl}
                method="post"
                className="flex flex-col gap-4"
            >
                {({ processing }) => (
                    <Button disabled={processing}>
                        {processing && <Spinner />}Confirm email and create
                        account
                    </Button>
                )}
            </Form>
            <Link
                href={login()}
                className="mt-4 inline-flex min-h-11 items-center justify-center text-sm text-muted-foreground underline underline-offset-4"
            >
                Cancel and return to sign-in
            </Link>
        </>
    );
}

MicrosoftContactConfirm.layout = {
    title: 'Confirm your contact email',
    description: 'Finish setting up your Microsoft sign-in',
};
