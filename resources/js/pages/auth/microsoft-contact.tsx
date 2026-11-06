import { Form, Head, Link } from '@inertiajs/react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/microsoft/contact';

export default function MicrosoftContact({
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
                Microsoft confirmed your identity. Choose a contact email for
                your Momento account. We will create the account only after you
                confirm the address. Open the verification link in this same
                browser session. Existing accounts are never linked.
            </p>
            {status && (
                <p role="status" aria-live="polite" className="mb-6 text-sm">
                    {status}
                </p>
            )}
            <Form
                {...store.form()}
                className="flex flex-col gap-6"
                onError={(errors) => {
                    if (errors.name) {
                        name.current?.focus();
                    } else if (errors.email) {
                        email.current?.focus();
                    }
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
                                    errors.email
                                        ? 'contact-help email-error'
                                        : 'contact-help'
                                }
                            />
                            <p
                                id="contact-help"
                                className="text-sm text-muted-foreground"
                            >
                                Use an address you can access. Existing accounts
                                are not linked to Microsoft sign-in.
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

MicrosoftContact.layout = {
    title: 'Verify your contact email',
    description: 'Finish creating your Microsoft account',
};
