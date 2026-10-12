/**
 * Minimal JSON client for the few Fortify endpoints that answer in JSON
 * (password-confirmation status and confirmation). Everything else goes
 * through Inertia. Sends Laravel's XSRF token from its cookie.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        public status: number,
        public body: { message?: string; errors?: Record<string, string[]> },
    ) {
        super(body.message ?? `Request failed (${status})`);
    }
}

export async function json<T = unknown>(method: string, url: string, data?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });

    const body = response.status === 204 ? {} : await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new HttpError(response.status, body);
    }
    return body as T;
}
