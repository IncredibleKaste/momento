import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { start } from '@/routes/microsoft';

type Props = { organizations: { key: string; name: string }[] };

export default function Microsoft({ organizations }: Props) {
    return (
        <>
            <Head title="Microsoft sign-in" />
            <div className="flex flex-col gap-4">
                <p className="text-sm text-muted-foreground">
                    Choose your organization. Microsoft accounts stay separate
                    from other Momento accounts, even when their email addresses
                    match.
                </p>
                {organizations.map((organization) => (
                    <Form
                        key={organization.key}
                        {...start.form(organization.key)}
                    >
                        {({ processing }) => (
                            <Button
                                className="h-auto min-h-11 w-full break-words whitespace-normal"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Continue with {organization.name}
                            </Button>
                        )}
                    </Form>
                ))}
                <Button asChild variant="ghost">
                    <Link href={login()}>Back to log in</Link>
                </Button>
            </div>
        </>
    );
}

Microsoft.layout = {
    title: 'Sign in with Microsoft',
    description: 'Use an account from an approved organization',
};
