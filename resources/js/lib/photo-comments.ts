export function commentLength(value: string): number {
    return Array.from(value).length;
}

export function isBlankComment(value: string): boolean {
    return /^\p{White_Space}*$/u.test(value);
}

export function xsrfHeaders(): HeadersInit {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
    };
}
