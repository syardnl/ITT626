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

export type LocationType = 'block' | 'floor' | 'unit' | 'common_area';
export type LocationImportance = 'normal' | 'important' | 'essential';

/** A Location as the Administrator endpoints return it. */
export interface AdminLocation {
    id: number;
    type: LocationType;
    code: string;
    name: string;
    parent_id: number | null;
    importance: LocationImportance;
    is_shared_area: boolean;
    active: boolean;
    children_count?: number;
}

export const locationTypeLabel: Record<LocationType, string> = {
    block: 'Block',
    floor: 'Floor',
    unit: 'Unit',
    common_area: 'Common Area',
};

/**
 * What may be added under each kind of node ("property" is the root). Mirrors
 * App\Enums\LocationType::allowedParents() on the server, which has the final say.
 */
export const childTypesUnder: Record<LocationType | 'property', LocationType[]> = {
    property: ['block', 'common_area'],
    block: ['floor', 'common_area'],
    floor: ['unit', 'common_area'],
    unit: [],
    common_area: [],
};
