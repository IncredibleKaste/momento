import { Form, Head, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { start as oidcStart } from '@/routes/oidc';
import { request } from '@/routes/password';
import { index as microsoftIndex } from '@/routes/microsoft';
import PasskeyVerify from '@/components/passkey-verify';

type PageProps = {
    errors?: {
        oidc?: string | string[];
    };
};

type Props = {
    status?: string;
    canResetPassword: boolean;
    canGoogleLogin?: boolean;
    canSwitchLogin?: boolean;
    canMicrosoftLogin?: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canGoogleLogin = false,
    canSwitchLogin = false,
    canMicrosoftLogin = false,
}: Props) {
    const oidcErrors = usePage<PageProps>().props.errors?.oidc;
    const oidcError = Array.isArray(oidcErrors) ? oidcErrors[0] : oidcErrors;

    return (
        <>
            <Head title="Welcome back" />

            {oidcError && (
                <p className="text-sm text-destructive" role="alert">
                    {oidcError}
                </p>
            )}

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder="you@example.com"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                />
                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                    role="alert"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    aria-invalid={Boolean(errors.password)}
                                    aria-describedby={
                                        errors.password
                                            ? 'password-error'
                                            : undefined
                                    }
                                />
                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                    role="alert"
                                />
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <Label
                                    htmlFor="remember"
                                    className="inline-flex min-h-11 cursor-pointer items-center gap-3"
                                >
                                    <Checkbox id="remember" name="remember" />
                                    Remember me
                                </Label>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-sm"
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Sign in
                            </Button>
                            {(canGoogleLogin ||
                                canSwitchLogin ||
                                canMicrosoftLogin) && (
                                <div className="border-t border-border pt-4 text-center text-xs text-muted-foreground">
                                    or sign in with a provider
                                </div>
                            )}
                            {canGoogleLogin && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full"
                                >
                                    <a href={oidcStart.url('google')}>
                                        Continue with Google
                                    </a>
                                </Button>
                            )}
                            {canSwitchLogin && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full"
                                >
                                    <a href={oidcStart.url('switch')}>
                                        Continue with SWITCH edu-ID
                                    </a>
                                </Button>
                            )}
                            {canMicrosoftLogin && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-full"
                                >
                                    <a href={microsoftIndex.url()}>
                                        Continue with Microsoft
                                    </a>
                                </Button>
                            )}
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            New here?{' '}
                            <TextLink href={register()}>
                                Create an account
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div
                    className="rounded-md border border-border bg-card p-4 text-sm leading-relaxed"
                    role="status"
                >
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Welcome back.',
    description: 'A little of your everyday, all in one place.',
};
