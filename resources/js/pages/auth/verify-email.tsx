// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    A verification link has been requested. Check your inbox
                    shortly, or try again if it does not arrive.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing, errors }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>

                        <InputError message={errors.email} />

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description:
        'Verify your email address to continue. If you need a verification link, request one below.',
};
