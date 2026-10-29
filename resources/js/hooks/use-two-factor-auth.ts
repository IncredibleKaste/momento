import { useHttp } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { qrCode, recoveryCodes, secretKey } from '@/routes/two-factor';

export type UseTwoFactorAuthReturn = {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    recoveryCodesList: string[];
    hasSetupData: boolean;
    errors: string[];
    clearSetupData: () => void;
    clearTwoFactorAuthData: () => void;
    fetchSetupData: () => Promise<void>;
    fetchRecoveryCodes: () => Promise<void>;
};

export const OTP_MAX_LENGTH = 6;

export const useTwoFactorAuth = (): UseTwoFactorAuthReturn => {
    const { submit } = useHttp();

    const [qrCodeSvg, setQrCodeSvg] = useState<string | null>(null);
    const [manualSetupKey, setManualSetupKey] = useState<string | null>(null);
    const [recoveryCodesList, setRecoveryCodesList] = useState<string[]>([]);
    const [errors, setErrors] = useState<string[]>([]);

    const hasSetupData = qrCodeSvg !== null && manualSetupKey !== null;

    const clearSetupData = useCallback((): void => {
        setManualSetupKey(null);
        setQrCodeSvg(null);
        setErrors([]);
    }, []);

    const clearTwoFactorAuthData = useCallback((): void => {
        setManualSetupKey(null);
        setQrCodeSvg(null);
        setErrors([]);
        setRecoveryCodesList([]);
    }, []);

    const fetchRecoveryCodes = useCallback(async (): Promise<void> => {
        try {
            setErrors([]);
            const codes = (await submit(recoveryCodes())) as string[];
            setRecoveryCodesList(codes);
        } catch {
            setErrors([
                'Could not load recovery codes. Reload Security settings to confirm your password, then try again.',
            ]);
            setRecoveryCodesList([]);
        }
    }, [submit]);

    const fetchSetupData = useCallback(async (): Promise<void> => {
        try {
            setErrors([]);
            const [qr, key] = await Promise.all([
                submit(qrCode()) as Promise<{ svg: string }>,
                submit(secretKey()) as Promise<{ secretKey: string }>,
            ]);
            setQrCodeSvg(qr.svg);
            setManualSetupKey(key.secretKey);
        } catch {
            setQrCodeSvg(null);
            setManualSetupKey(null);
            setErrors([
                'Could not load setup details. Retry, or reload Security settings to confirm your password again.',
            ]);
        }
    }, [submit]);

    return {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        hasSetupData,
        errors,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        fetchRecoveryCodes,
    };
};
