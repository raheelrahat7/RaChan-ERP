export class ApiError extends Error {
    readonly status: number;
    readonly errors: Record<string, string[]>;

    constructor(
        message: string,
        status: number,
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.status = status;
        this.errors = errors;
    }

    /** First message per field, for showing next to inputs. */
    fieldErrors(): Record<string, string> {
        return Object.fromEntries(
            Object.entries(this.errors).map(([field, messages]) => [
                field,
                messages[0] ?? '',
            ]),
        );
    }
}

function xsrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

/** JSON request to a session-authenticated endpoint; throws ApiError with Laravel's validation errors. */
export async function apiJson<T>(
    path: string,
    method = 'GET',
    data?: unknown,
): Promise<T> {
    const options: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
    };
    if (data !== undefined) {
        options.body = JSON.stringify(data);
    }
    const response = await fetch(path, options);
    if (!response.ok) {
        const details = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const first = Object.values(details.errors ?? {}).flat()[0];
        throw new ApiError(
            first ?? details.message ?? `Request failed (${response.status}).`,
            response.status,
            details.errors ?? {},
        );
    }

    return response.json() as Promise<T>;
}
