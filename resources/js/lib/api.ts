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

export type Priority = 'critical' | 'high' | 'medium' | 'low';

/** Most urgent first, matching App\Enums\Priority on the server. */
export const priorities: Priority[] = ['critical', 'high', 'medium', 'low'];

export const priorityLabel: Record<Priority, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

export interface Skill {
    id: number;
    category_id: number;
    name: string;
    active: boolean;
}

export interface Category {
    id: number;
    name: string;
    default_priority: Priority;
    active: boolean;
    /** Present on the Administrator endpoints only. */
    skill?: Skill;
}

/** An SLA Target: minutes allowed for Response and Resolution at one Priority. */
export interface SlaRule {
    id: number;
    priority: Priority;
    response_minutes: number;
    resolution_minutes: number;
    version: number;
    updated_at: string | null;
}

/** 90 → "1 h 30 min", 4320 → "3 days". */
export function formatMinutes(total: number): string {
    const days = Math.floor(total / 1440);
    const hours = Math.floor((total % 1440) / 60);
    const minutes = total % 60;
    const parts = [
        days && `${days} ${days === 1 ? 'day' : 'days'}`,
        hours && `${hours} h`,
        minutes && `${minutes} min`,
    ].filter(Boolean);
    return parts.length ? parts.join(' ') : '0 min';
}

export type ResidentType = 'owner' | 'tenant' | 'student';

export const roleLabel: Record<Role, string> = {
    resident: 'Resident',
    manager: 'Manager',
    technician: 'Technician',
    administrator: 'Administrator',
};

export const residentTypeLabel: Record<ResidentType, string> = {
    owner: 'Owner',
    tenant: 'Tenant',
    student: 'Student',
};

/** An account as the Administrator endpoints return it. */
export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: Role;
    resident_type: ResidentType | null;
    unit: { id: number; code: string; name: string } | null;
    active: boolean;
    /** False while the set-password link is still outstanding. */
    password_set: boolean;
    created_at: string;
}

export type TechnicianType = 'in_house' | 'contractor';
export type Availability = 'available' | 'busy' | 'on_leave';

export const technicianTypeLabel: Record<TechnicianType, string> = {
    in_house: 'In-house',
    contractor: 'Contractor',
};

export const availabilityLabel: Record<Availability, string> = {
    available: 'Available',
    busy: 'Busy',
    on_leave: 'On leave',
};

/** A Technician profile as /technicians returns it. */
export interface Technician {
    id: number;
    user: { id: number; name: string; email: string; active: boolean };
    type: TechnicianType;
    company_name: string | null;
    skills: { id: number; name: string; active: boolean }[];
    availability: Availability;
    max_tasks: number;
    home_block: { id: number; code: string; name: string };
}

/** Choices for the Technician profile form (GET /technicians/form-options). */
export interface TechnicianFormOptions {
    accounts: { id: number; name: string; email: string }[];
    skills: { id: number; name: string }[];
    blocks: { id: number; code: string; name: string }[];
}
