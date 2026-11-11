import { expect, it } from 'vite-plus/test';
import {
    canAnimateAuthLoginMoments,
    canOfferAuthLoginMotionControl,
    nextAuthLoginMomentIndex,
    readyAuthLoginMoments,
    selectCurrentAuthLoginMoment,
    type AuthLoginMoment,
} from '../../resources/js/components/auth-login-moments';

const firstMoment: AuthLoginMoment = {
    src: '/images/auth-login/lake.jpg',
    alt: 'A swimmer in a quiet alpine lake',
};
const secondMoment: AuthLoginMoment = {
    src: '/images/auth-login/dinner.jpg',
    alt: 'Friends gathered around a dinner table',
};

it('keeps only decoded moments and uses the static fallback when none decode', () => {
    const results: PromiseSettledResult<AuthLoginMoment>[] = [
        { status: 'fulfilled', value: firstMoment },
        { status: 'rejected', reason: new Error('Image decode failed') },
        { status: 'fulfilled', value: secondMoment },
    ];

    expect(readyAuthLoginMoments(results)).toEqual([firstMoment, secondMoment]);
    expect(readyAuthLoginMoments(results.slice(1, 2))).toEqual([]);
});

it('animates only when decoded slides and every motion condition allows it', () => {
    const enabledState = {
        ready: true,
        slideCount: 2,
        userPaused: false,
        reducedMotion: false,
        pageHidden: false,
    };

    expect(canAnimateAuthLoginMoments(enabledState)).toBe(true);
    expect(canAnimateAuthLoginMoments({ ...enabledState, ready: false })).toBe(
        false,
    );
    expect(canAnimateAuthLoginMoments({ ...enabledState, slideCount: 1 })).toBe(
        false,
    );
    expect(
        canAnimateAuthLoginMoments({ ...enabledState, userPaused: true }),
    ).toBe(false);
    expect(
        canAnimateAuthLoginMoments({ ...enabledState, reducedMotion: true }),
    ).toBe(false);
    expect(
        canAnimateAuthLoginMoments({ ...enabledState, pageHidden: true }),
    ).toBe(false);
    expect(
        canOfferAuthLoginMotionControl({
            ready: true,
            slideCount: 2,
            reducedMotion: false,
        }),
    ).toBe(true);
    expect(
        canOfferAuthLoginMotionControl({
            ready: true,
            slideCount: 1,
            reducedMotion: false,
        }),
    ).toBe(false);
    expect(
        canOfferAuthLoginMotionControl({
            ready: true,
            slideCount: 0,
            reducedMotion: false,
        }),
    ).toBe(false);
});

it('advances through decoded slides and wraps to the first', () => {
    expect(nextAuthLoginMomentIndex(0, 2)).toBe(1);
    expect(nextAuthLoginMomentIndex(1, 2)).toBe(0);
    expect(nextAuthLoginMomentIndex(0, 1)).toBe(0);
});

it('keeps the active photo when another slide fails and falls back when it fails', () => {
    expect(selectCurrentAuthLoginMoment([firstMoment], firstMoment.src)).toBe(
        firstMoment,
    );
    expect(selectCurrentAuthLoginMoment([secondMoment], firstMoment.src)).toBe(
        secondMoment,
    );
    expect(selectCurrentAuthLoginMoment([], firstMoment.src)).toBeUndefined();
});
