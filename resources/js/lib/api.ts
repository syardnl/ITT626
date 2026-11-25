import axios, { AxiosError } from 'axios';

/**
 * Same-origin client for /api/v1. Session cookies travel automatically; axios
 * copies the XSRF-TOKEN cookie into the X-XSRF-TOKEN header (ADR 0001).
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export type Role = 'resident' | 'manager' | 'technician' | 'administrator';

export interface Account {
    id: number;
    name: string;
    email: string;
    role: Role;
    resident_type: 'owner' | 'tenant' | 'student' | null;
    unit: { id: number; code: string; name: string } | null;
}

/** The API error envelope: {"message": ..., "errors": {...}} (design §8). */
export interface ApiError {
    status: number;
    message: string;
    errors: Record<string, string[]>;
}

export function toApiError(error: unknown): ApiError {
    if (error instanceof AxiosError && error.response) {
        const body = error.response.data as Partial<ApiError> | undefined;
        return {
            status: error.response.status,
            message: body?.message ?? 'Something went wrong.',
            errors: body?.errors ?? {},
        };
    }
    return { status: 0, message: 'Cannot reach the server. Check your connection and try again.', errors: {} };
}

/** Make sure the XSRF-TOKEN cookie exists before the first state-changing request. */
export async function ensureCsrfCookie(): Promise<void> {
    if (!document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='))) {
        await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
    }
}

/** Laravel's paginated resource collection shape. */
export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/** The Property's timezone, published by the server in the page shell. */
export const propertyTimeZone: string =
    document.querySelector<HTMLMetaElement>('meta[name="property-timezone"]')?.content || 'Asia/Kuala_Lumpur';

/** Timestamps arrive in UTC; people read them in the Property's timezone. */
export function formatDateTime(utc: string): string {
    return new Intl.DateTimeFormat('en-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: propertyTimeZone,
    }).format(new Date(utc));
}
