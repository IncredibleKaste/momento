export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

type AppearanceState = Readonly<{
    appearance: Appearance;
    resolvedAppearance: ResolvedAppearance;
}>;

const serverSnapshot: AppearanceState = {
    appearance: 'system',
    resolvedAppearance: 'light',
};
let snapshot = serverSnapshot;
let initialized = false;
let systemTheme: MediaQueryList | null = null;
const listeners = new Set<() => void>();

function normalizeAppearance(value: unknown): Appearance {
    return value === 'light' || value === 'dark' ? value : 'system';
}

function applyAppearance(appearance: Appearance): void {
    const resolvedAppearance =
        appearance === 'dark' ||
        (appearance === 'system' && systemTheme?.matches)
            ? 'dark'
            : 'light';
    const root = document.documentElement;
    root.dataset.appearance = appearance;
    root.classList.toggle('dark', resolvedAppearance === 'dark');
    root.style.colorScheme = resolvedAppearance;

    if (
        snapshot.appearance !== appearance ||
        snapshot.resolvedAppearance !== resolvedAppearance
    ) {
        snapshot = { appearance, resolvedAppearance };
        listeners.forEach((listener) => listener());
    }
}

function persistCookie(appearance: Appearance): void {
    try {
        document.cookie = `appearance=${appearance};path=/;max-age=31536000;SameSite=Lax`;
    } catch {
        // Appearance remains usable when browser persistence is blocked.
    }
}

export function getAppearanceSnapshot(): AppearanceState {
    return snapshot;
}

export function getServerAppearanceSnapshot(): AppearanceState {
    return serverSnapshot;
}

export function subscribeToAppearance(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function initializeTheme(): void {
    if (typeof window === 'undefined' || initialized) {
        return;
    }

    initialized = true;
    systemTheme =
        typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-color-scheme: dark)')
            : null;
    applyAppearance(
        normalizeAppearance(document.documentElement.dataset.appearance),
    );
    persistCookie(snapshot.appearance);
    systemTheme?.addEventListener('change', () =>
        applyAppearance(snapshot.appearance),
    );
    window.addEventListener('storage', (event: StorageEvent) => {
        if (event.key !== 'appearance' && event.key !== null) {
            return;
        }

        try {
            if (event.storageArea !== window.localStorage) {
                return;
            }
        } catch {
            return;
        }

        const appearance = normalizeAppearance(event.newValue);
        applyAppearance(appearance);
        persistCookie(appearance);
    });
}

export function updateAppearance(appearance: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    const mode = normalizeAppearance(appearance);
    applyAppearance(mode);
    try {
        window.localStorage.setItem('appearance', mode);
    } catch {
        // A failed localStorage write must not prevent the cookie fallback.
    }
    persistCookie(mode);
}
