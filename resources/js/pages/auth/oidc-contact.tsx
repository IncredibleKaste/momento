import { Form, Head, Link } from '@inertiajs/react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

export default function OidcContact({
    suggestedName,
    status,
}: {
    suggestedName: string;
    status?: string;
}) {
    const name = useRef<HTMLInputElement>(null);
    const email = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Verify your contact email" />
            <p className="mb-6 text-sm text-muted-foreground">
                Your identity provider confirmed your sign-in. Choose a contact
                email for your Momento account. We create the account only after
                you confirm the address in this browser session. Existing
                accounts are never linked.
            </p>
            {status && (
                <p role="status" aria-live="polite" className="mb-6 text-sm">
                    {status}
                </p>
            )}
            <Form
                action="/auth/oidc/contact"
                method="post"
                className="flex flex-col gap-6"
                onError={(errors) => {
                    if (errors.name) name.current?.focus();
                    else if (errors.email) email.current?.focus();
                }}
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={suggestedName}
                                ref={name}
                                maxLength={255}
                                autoComplete="name"
                                required
                                autoFocus
                                aria-invalid={Boolean(errors.name)}
                                aria-describedby={
                                    errors.name ? 'name-error' : undefined
                                }
                            />
                            <InputError
                                id="name-error"
                                message={errors.name}
                                role="alert"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Contact email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                ref={email}
                                maxLength={255}
                                autoComplete="email"
                                required
                                aria-invalid={Boolean(errors.email)}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                Choose an address you can access. Existing
                                accounts are not linked to this sign-in.
                            </p>
                            <InputError
                                id="email-error"
                                message={errors.email}
                                role="alert"
                            />
                        </div>
                        <Button disabled={processing}>
                            {processing && <Spinner />}Send verification link
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href={login()}>Cancel</Link>
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

OidcContact.layout = {
    title: 'Verify your contact email',
    description: 'Finish setting up your sign-in',
};
