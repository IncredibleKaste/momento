import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { afterEach, expect, it, vi } from 'vite-plus/test';

const template = readFileSync(
    new URL('../../resources/views/app.blade.php', import.meta.url),
    'utf8',
);
const bootstrap = template.match(/<script>([\s\S]*?)<\/script>/)![1];

async function browser({
    stored = null,
    cookie = 'system',
    dark = false,
    failRead = false,
    failWrite = false,
    failCookie = false,
    denyStorage = false,
    hasSystemTheme = true,
}: {
    stored?: string | null;
    cookie?: string;
    dark?: boolean;
    failRead?: boolean;
    failWrite?: boolean;
    failCookie?: boolean;
    denyStorage?: boolean;
    hasSystemTheme?: boolean;
} = {}) {
    vi.resetModules();
    const classes = new Set<string>();
    const root = {
        dataset: { appearance: cookie },
        style: { colorScheme: '' },
        classList: {
            toggle(name: string, active: boolean) {
                if (active) classes.add(name);
                else classes.delete(name);
            },
        },
    };
    let savedCookie = '';
    const document = { documentElement: root };
    Object.defineProperty(document, 'cookie', {
        get: () => savedCookie,
        set(value: string) {
            if (failCookie) throw new Error('Cookie denied');
            savedCookie = value;
        },
    });
    const storage = {
        getItem: vi.fn(() => {
            if (failRead) throw new Error('Read denied');
            return stored;
        }),
        setItem: vi.fn((_key: string, value: string) => {
            if (failWrite) throw new Error('Write denied');
            stored = value;
        }),
    };
    const query = Object.assign(new EventTarget(), { matches: dark });
    const window = Object.assign(new EventTarget(), {
        localStorage: storage,
        matchMedia: vi.fn(() => query),
    });
    if (denyStorage) {
        Object.defineProperty(window, 'localStorage', {
            get() {
                throw new Error('Storage denied');
            },
        });
    }
    if (!hasSystemTheme) {
        Object.defineProperty(window, 'matchMedia', { value: undefined });
    }
    vi.stubGlobal('window', window);
    vi.stubGlobal('document', document);
    runInNewContext(bootstrap, { document, window });
    const store = await import('../../resources/js/lib/appearance');
    store.initializeTheme();

    return {
        ...store,
        root,
        classes,
        query,
        storage,
        window,
        cookie: () => savedCookie,
        storageEvent(key: string | null, value: string | null, area = storage) {
            window.dispatchEvent(
                Object.assign(new Event('storage'), {
                    key,
                    newValue: value,
                    storageArea: area,
                }),
            );
        },
    };
}

afterEach(() => vi.unstubAllGlobals());

it.each([false, true])(
    'uses the system default before app boot (dark=%s)',
    async (dark) => {
        const b = await browser({ dark });
        expect(b.getAppearanceSnapshot()).toEqual({
            appearance: 'system',
            resolvedAppearance: dark ? 'dark' : 'light',
        });
        expect(b.root.style.colorScheme).toBe(dark ? 'dark' : 'light');
        expect(b.classes.has('dark')).toBe(dark);
        expect(b.getAppearanceSnapshot()).toBe(b.getAppearanceSnapshot());
    },
);

it('prefers a valid local choice over the cookie and device theme', async () => {
    const b = await browser({ stored: 'light', cookie: 'dark', dark: true });
    expect(b.getAppearanceSnapshot()).toEqual({
        appearance: 'light',
        resolvedAppearance: 'light',
    });
    expect(b.cookie()).toContain('appearance=light;');
});

it('uses a valid cookie when local preference is invalid or unreadable', async () => {
    const invalid = await browser({
        stored: '<script>fictional</script>',
        cookie: 'dark',
    });
    expect(invalid.root.dataset.appearance).toBe('dark');
    const blocked = await browser({ cookie: 'dark', failRead: true });
    expect(blocked.root.style.colorScheme).toBe('dark');
});

it('falls back to system for invalid values without retaining them in the DOM', async () => {
    const b = await browser({ stored: 'sepia', cookie: 'invalid', dark: true });
    expect(b.root.dataset.appearance).toBe('system');
    expect(b.classes.has('dark')).toBe(true);
});

it('keeps manual choices usable when local writes fail and still saves the cookie', async () => {
    const b = await browser({ failWrite: true });
    b.updateAppearance('dark');
    expect(b.getAppearanceSnapshot().appearance).toBe('dark');
    expect(b.cookie()).toContain('appearance=dark;');
});

it('still saves local preference when cookie writes fail', async () => {
    const b = await browser({ failCookie: true });
    b.updateAppearance('dark');
    expect(b.storage.setItem).toHaveBeenCalledWith('appearance', 'dark');
    expect(b.root.style.colorScheme).toBe('dark');
});

it.each([false, true])(
    'survives blocked persistence including a denied storage getter (%s)',
    async (denyStorage) => {
        const b = await browser({
            failRead: true,
            failWrite: true,
            failCookie: true,
            denyStorage,
        });
        b.updateAppearance('dark');
        expect(b.getAppearanceSnapshot().resolvedAppearance).toBe('dark');
        expect(b.classes.has('dark')).toBe(true);
        if (denyStorage) {
            b.storageEvent('appearance', 'light');
            expect(b.getAppearanceSnapshot().resolvedAppearance).toBe('dark');
        }
    },
);

it('notifies subscribers of resolved system changes while preserving manual choices', async () => {
    const b = await browser();
    const changed = vi.fn();
    const unsubscribe = b.subscribeToAppearance(changed);
    b.query.matches = true;
    b.query.dispatchEvent(new Event('change'));
    expect(changed).toHaveBeenCalledTimes(1);
    expect(b.getAppearanceSnapshot()).toEqual({
        appearance: 'system',
        resolvedAppearance: 'dark',
    });
    b.updateAppearance('light');
    changed.mockClear();
    b.query.matches = false;
    b.query.dispatchEvent(new Event('change'));
    b.query.matches = true;
    b.query.dispatchEvent(new Event('change'));
    expect(changed).not.toHaveBeenCalled();
    expect(b.getAppearanceSnapshot().resolvedAppearance).toBe('light');
    unsubscribe();
    b.updateAppearance('system');
    expect(changed).not.toHaveBeenCalled();
});

it('synchronizes other-tab changes and ignores unrelated or session storage', async () => {
    const b = await browser();
    b.storageEvent('appearance', 'dark');
    expect(b.getAppearanceSnapshot().appearance).toBe('dark');
    expect(b.cookie()).toContain('appearance=dark;');
    b.storageEvent('other', 'light');
    b.storageEvent('appearance', 'light', {
        getItem: vi.fn(),
        setItem: vi.fn(),
    });
    expect(b.getAppearanceSnapshot().appearance).toBe('dark');
});

it.each(['appearance', null])(
    'resets to live system after other-tab removal/clear (%s)',
    async (key) => {
        const b = await browser({ stored: 'light', dark: true });
        b.storageEvent(key, null);
        expect(b.getAppearanceSnapshot()).toEqual({
            appearance: 'system',
            resolvedAppearance: 'dark',
        });
        expect(b.cookie()).toContain('appearance=system;');
    },
);

it('does not duplicate system listeners when initialized again', async () => {
    const b = await browser();
    b.initializeTheme();
    const changed = vi.fn();
    b.subscribeToAppearance(changed);
    b.query.matches = true;
    b.query.dispatchEvent(new Event('change'));
    expect(changed).toHaveBeenCalledTimes(1);
    expect(b.window.matchMedia).toHaveBeenCalledTimes(2);
});

it('can be imported and initialized during server rendering without browser globals', async () => {
    vi.resetModules();
    const store = await import('../../resources/js/lib/appearance');
    store.initializeTheme();
    store.updateAppearance('dark');
    expect(store.getAppearanceSnapshot()).toEqual({
        appearance: 'system',
        resolvedAppearance: 'light',
    });
    expect(store.getServerAppearanceSnapshot()).toBe(
        store.getServerAppearanceSnapshot(),
    );
});

it('uses light for unavailable system detection while keeping manual dark usable', async () => {
    const b = await browser({ hasSystemTheme: false });
    expect(b.getAppearanceSnapshot()).toEqual({
        appearance: 'system',
        resolvedAppearance: 'light',
    });
    b.updateAppearance('dark');
    expect(b.root.style.colorScheme).toBe('dark');
});
