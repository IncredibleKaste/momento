import { Form } from '@inertiajs/react';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: Props) {
    const [codesAreVisible, setCodesAreVisible] = useState<boolean>(false);
    const [loading, setLoading] = useState(false);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;

    const toggleCodesVisibility = async () => {
        if (!codesAreVisible && !recoveryCodesList.length) {
            setLoading(true);
            await fetchRecoveryCodes();
            setLoading(false);
        }

        setCodesAreVisible(!codesAreVisible);
    };

    const RecoveryCodeIconComponent = codesAreVisible ? EyeOff : Eye;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex gap-3">
                    <LockKeyhole className="size-4" aria-hidden="true" />
                    2FA recovery codes
                </CardTitle>
                <CardDescription>
                    Recovery codes let you regain access if you lose your 2FA
                    device. Store them in a secure password manager.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between">
                    <Button
                        onClick={toggleCodesVisibility}
                        disabled={loading}
                        className="w-fit"
                        aria-expanded={codesAreVisible}
                        aria-controls="recovery-codes-section"
                    >
                        <RecoveryCodeIconComponent
                            className="size-4"
                            aria-hidden="true"
                        />
                        {loading
                            ? 'Loading'
                            : codesAreVisible
                              ? 'Hide'
                              : 'View'}{' '}
                        recovery codes
                    </Button>

                    {canRegenerateCodes && (
                        <Form
                            {...regenerateRecoveryCodes.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={fetchRecoveryCodes}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="secondary"
                                    type="submit"
                                    disabled={processing}
                                    aria-describedby="regenerate-warning"
                                >
                                    <RefreshCw /> Regenerate codes
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
                {codesAreVisible && (
                    <div id="recovery-codes-section" className="relative">
                        <div className="mt-3 space-y-3">
                            {errors?.length ? (
                                <div className="space-y-3">
                                    <AlertError errors={errors} />
                                    <Button
                                        variant="outline"
                                        onClick={fetchRecoveryCodes}
                                    >
                                        Retry loading codes
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <div
                                        className="grid gap-1 rounded-lg bg-muted p-4 font-mono text-sm"
                                        role="list"
                                        aria-label="Recovery codes"
                                    >
                                        {recoveryCodesList.length ? (
                                            recoveryCodesList.map(
                                                (code, index) => (
                                                    <div
                                                        key={index}
                                                        role="listitem"
                                                        className="select-text"
                                                    >
                                                        {code}
                                                    </div>
                                                ),
                                            )
                                        ) : (
                                            <div
                                                className="space-y-2"
                                                aria-label="Loading recovery codes"
                                            >
                                                {Array.from(
                                                    { length: 8 },
                                                    (_, index) => (
                                                        <div
                                                            key={index}
                                                            className="h-4 animate-pulse rounded bg-muted-foreground/20"
                                                            aria-hidden="true"
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    <div className="text-xs text-muted-foreground select-none">
                                        <p id="regenerate-warning">
                                            Each recovery code can be used once
                                            to access your account. Regenerating
                                            codes invalidates every previous
                                            code. Save the new set in a secure
                                            password manager.
                                        </p>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
