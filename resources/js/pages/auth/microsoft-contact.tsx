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
}: {
    suggestedName: string;
}) {
    const name = useRef<HTMLInputElement>(null);
    const email = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Verify your contact email" />
            <p className="mb-6 text-sm text-muted-foreground">
                Microsoft confirmed your identity. Choose a contact email for
                your new Momento account. We’ll send a verification link before
                you can enter the community. This does not link an existing
                account.
            </p>
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
                            />
                            <InputError message={errors.name} />
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
                                aria-describedby="contact-help"
                            />
                            <p
                                id="contact-help"
                                className="text-sm text-muted-foreground"
                            >
                                Use an address you can open. It must not already
                                belong to another Momento account.
                            </p>
                            <InputError message={errors.email} />
                        </div>
                        <Button disabled={processing}>
                            {processing && <Spinner />}Create account and verify
                            email
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
