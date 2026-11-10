import type { ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, it, vi } from 'vite-plus/test';
import Dashboard from '../../resources/js/pages/dashboard';

const capturedClicks = vi.hoisted(() => new Map<string, unknown>());

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    const React = await import('react');

    function CaptureAnchor({
        children,
        onClick,
        ...props
    }: ComponentProps<'a'>) {
        if (typeof children === 'string' && onClick) {
            capturedClicks.set(children, onClick);
        }

        return React.createElement('a', { ...props, onClick }, children);
    }

    return {
        ...actual,
        Head: () => null,
        Link: (props: ComponentProps<typeof actual.Link>) =>
            React.createElement(actual.Link, { ...props, as: CaptureAnchor }),
    };
});

class TestHTMLElement {}

class TestAnchor extends TestHTMLElement {
    tagName = 'A';
    target = '';
}

function stubAnchorGlobals() {
    vi.stubGlobal('HTMLElement', TestHTMLElement);
    vi.stubGlobal('HTMLAnchorElement', TestAnchor);
}

function renderDashboard() {
    capturedClicks.clear();
    const markup = renderToStaticMarkup(
        <Dashboard
            followedPeople={[]}
            photoPosts={{
                data: [],
                nextCursor: 'older',
                previousCursor: 'newer',
            }}
        />,
    );

    return markup;
}

function clickEvent(overrides: Record<string, unknown> = {}) {
    const anchor = Object.assign(new TestAnchor(), {
        hasAttribute: () => false,
    });
    const event = {
        defaultPrevented: false,
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        target: anchor,
        currentTarget: anchor,
        preventDefault() {
            this.defaultPrevented = true;
        },
        ...overrides,
    };

    return event;
}

it.each([
    { label: 'Refresh photos', preserveScroll: false },
    { label: 'Newer photos', preserveScroll: true },
    { label: 'Older photos', preserveScroll: true },
])(
    'routes $label through supported Inertia failure callbacks',
    async ({ label, preserveScroll }) => {
        const { router } = await import('@inertiajs/react');
        stubAnchorGlobals();
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});

        renderDashboard();
        const click = capturedClicks.get(label) as (
            event: ReturnType<typeof clickEvent>,
        ) => void;
        const event = clickEvent();
        click(event as never);

        expect(event.defaultPrevented).toBe(true);
        expect(get).toHaveBeenCalledOnce();
        expect(visit).not.toHaveBeenCalled();

        const options = get.mock.calls[0]?.[2];
        expect(options?.preserveScroll).toBe(preserveScroll);
        expect(options?.onNetworkError?.(new Error('offline'))).toBe(false);
        expect(options?.onHttpException?.({ status: 429 } as never)).toBe(
            false,
        );
        expect(options?.onHttpException?.({ status: 503 } as never)).toBe(
            false,
        );
        expect(
            options?.onHttpException?.({ status: 401 } as never),
        ).toBeUndefined();

        get.mockRestore();
        visit.mockRestore();
    },
);

it('leaves modified clicks to the browser', async () => {
    const { router } = await import('@inertiajs/react');
    stubAnchorGlobals();
    const get = vi.spyOn(router, 'get').mockImplementation(() => {});
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});

    renderDashboard();
    const click = capturedClicks.get('Refresh photos') as (
        event: ReturnType<typeof clickEvent>,
    ) => void;
    const event = clickEvent({ ctrlKey: true });
    click(event as never);

    expect(event.defaultPrevented).toBe(false);
    expect(get).not.toHaveBeenCalled();
    expect(visit).not.toHaveBeenCalled();

    get.mockRestore();
    visit.mockRestore();
});
