import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, it, vi } from 'vite-plus/test';
import PhotoLikeButton from '../../resources/js/components/photo-like-button';

vi.mock('../../resources/js/components/ui/button', async () => {
    const React = await import('react');

    return {
        Button: ({ children, ...props }: React.ComponentProps<'button'>) =>
            React.createElement('button', props, children),
    };
});

it.each([
    { liked: false, label: 'Like photo', pressed: 'false' },
    { liked: true, label: 'Unlike photo', pressed: 'true' },
])(
    'renders the acknowledged heart state accessibly ($label)',
    ({ liked, label, pressed }) => {
        const html = renderToStaticMarkup(
            createElement(PhotoLikeButton, {
                postId: 7,
                liked,
                revision: liked ? 4 : 0,
            }),
        );

        expect(html).toContain(`aria-label="${label}"`);
        expect(html).toContain(`aria-pressed="${pressed}"`);
        expect(html).toContain('min-h-11 min-w-11');
        expect(html).not.toMatch(/\b(count|likers|liked by)\b/i);
    },
);
