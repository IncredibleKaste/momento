import { expect, it } from 'vite-plus/test';
import { classifyAttachmentHttpFailure } from '../../resources/js/components/attachment-composer';

it('treats retry-key conflicts as rejected drafts instead of polling another send', () => {
    expect(classifyAttachmentHttpFailure(409)).toEqual({
        attemptStatus: 'failed',
        message:
            'This upload key belongs to different content. Your draft is still here; retry creates a fresh upload key.',
    });
});

it('checks status after server errors that may follow accepted uploads', () => {
    expect(classifyAttachmentHttpFailure(500)).toEqual({
        attemptStatus: 'unknown',
        message:
            'The server did not confirm whether this upload was accepted. Check its status before trying again.',
    });
});

it('rejects unauthenticated upload posts without treating them as uncertain', () => {
    expect(classifyAttachmentHttpFailure(401)).toEqual({
        attemptStatus: 'failed',
        message:
            'Your sign-in expired. Copy your message and selected files, then sign in again before retrying.',
    });
});
