import { useSyncExternalStore } from 'react';
import {
    getAppearanceSnapshot,
    getServerAppearanceSnapshot,
    subscribeToAppearance,
    updateAppearance,
} from '@/lib/appearance';

export type { Appearance, ResolvedAppearance } from '@/lib/appearance';

export function useAppearance() {
    const snapshot = useSyncExternalStore(
        subscribeToAppearance,
        getAppearanceSnapshot,
        getServerAppearanceSnapshot,
    );

    return { ...snapshot, updateAppearance } as const;
}
