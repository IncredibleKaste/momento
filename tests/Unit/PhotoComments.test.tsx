import { expect, it, vi } from 'vite-plus/test';
import { readFileSync } from 'node:fs';
import { renderToStaticMarkup } from 'react-dom/server';
import { PlainCommentText } from '../../resources/js/components/plain-comment-text';

const commentsPanelSource = readFileSync(
    new URL(
        '../../resources/js/components/photo-comments-panel.tsx',
        import.meta.url,
    ),
    'utf8',
);
import {
    commentLength,
    isBlankComment,
    xsrfHeaders,
} from '../../resources/js/lib/photo-comments';

it('counts Unicode code points rather than UTF-16 code units', () => {
    expect(commentLength('a😀é')).toBe(4);
    expect(commentLength('😀'.repeat(500))).toBe(500);
    expect(commentLength('😀'.repeat(501))).toBe(501);
});

it('treats Unicode whitespace as blank without rewriting valid text', () => {
    expect(isBlankComment(' \t\n')).toBe(true);
    expect(isBlankComment('\u00a0\u2003')).toBe(true);
    expect(isBlankComment('\u202f\u3000\u0085')).toBe(true);
    expect(isBlankComment('\u00a0 keep \u00a0')).toBe(false);
});

it('sends the decoded Laravel XSRF cookie in the expected JSON headers', () => {
    vi.stubGlobal('document', {
        cookie: 'other=value; XSRF-TOKEN=encrypted%2Btoken',
    });
    expect(xsrfHeaders()).toEqual({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': 'encrypted+token',
    });
    vi.unstubAllGlobals();
});

it('renders untrusted comment text as escaped text, not markup', () => {
    const markup = renderToStaticMarkup(
        <PlainCommentText body={'<script>alert("x")</script>'} />,
    );
    expect(markup).toContain('&lt;script&gt;');
    expect(markup).not.toContain('<script>');
});

it('keys every stateful comments panel by photo post identity', () => {
    const wrapper = commentsPanelSource.match(
        /export function PhotoCommentsPanel[\s\S]*?\n}/,
    )?.[0];
    const inner = commentsPanelSource.slice(
        commentsPanelSource.indexOf('function PhotoCommentsPanelForPost'),
    );

    expect(wrapper).toContain('key={photoPostId}');
    expect(wrapper).not.toContain('useState');
    expect(inner).toContain('useState');
    expect(inner).toContain('useEffect');
    expect(inner).toContain('submissionKey');
});
