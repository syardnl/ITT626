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

/** A node of the active Location tree (GET /locations). */
export interface LocationNode {
    id: number;
    type: LocationType;
    code: string;
    name: string;
    importance: LocationImportance;
    is_shared_area: boolean;
    children: LocationNode[];
}

export type ComplaintStatus =
    | 'reported' | 'under_review' | 'assigned' | 'in_progress'
    | 'pending_verification' | 'resolved' | 'returned' | 'closed';

/** The case-study status names (design §5). */
export const complaintStatusLabel: Record<ComplaintStatus, string> = {
    reported: 'Reported',
    under_review: 'Under Review',
    assigned: 'Assigned',
    in_progress: 'In Progress',
    pending_verification: 'Pending Verification',
    resolved: 'Resolved',
    returned: 'Returned',
    closed: 'Closed',
};

export type Urgency = 'low' | 'medium' | 'high' | 'critical';
export type AffectedUnits = 'one' | 'several' | 'many';

/** Shown under each Urgency choice on the Report form. */
export const urgencyHint: Record<Urgency, string> = {
    low: 'An inconvenience that can wait a few days.',
    medium: 'Needs fixing soon, but daily life goes on.',
    high: 'Seriously disrupts daily life.',
    critical: 'Cannot wait: needs attention right away.',
};

export const urgencyLabel: Record<Urgency, string> = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };

export const affectedUnitsLabel: Record<AffectedUnits, string> = {
    one: 'Just one Unit',
    several: 'Several Units (2–10)',
    many: 'Many Units (more than 10, or a whole Block)',
};

/** A Complaint as a list shows it. */
export interface Complaint {
    id: number;
    reference_no: string;
    title: string;
    status: ComplaintStatus;
    priority: Priority;
    safety_risk: boolean;
    category: { id: number; name: string } | null;
    location: { id: number; code: string; name: string; type: LocationType };
    created_at: string;
}

/**
 * Someone else's Complaint as an list of open Complaints at a Location or a Follower sees it: reference, title,
 * Category, status and date only. `following` and `mine` describe the viewer.
 */
export interface ComplaintSummary {
    id: number;
    reference_no: string;
    title: string;
    category: { id: number; name: string } | null;
    status: ComplaintStatus;
    created_at: string;
    following: boolean;
    mine: boolean;
}

export interface StatusTimelineEntry {
    type: 'status';
    id: number;
    from_status: ComplaintStatus | null;
    to_status: ComplaintStatus;
    reason: string | null;
    actor: { id: number; name: string } | null;
    at: string;
}

export type CommentVisibility = 'public' | 'internal';

/** Public Comments reach the reporter; Internal Comments only the management office. */
export interface CommentTimelineEntry {
    type: 'comment';
    id: number;
    visibility: CommentVisibility;
    body: string;
    author: { id: number; name: string; role: Role | null };
    at: string;
}

/** A Manager confirming or changing the Category and Priority; shown to the office only. */
export interface ClassificationTimelineEntry {
    type: 'classification';
    id: number;
    from_category: { id: number; name: string } | null;
    to_category: { id: number; name: string };
    from_priority: Priority;
    to_priority: Priority;
    reason: string | null;
    actor: { id: number; name: string };
    at: string;
}

/** A Technician assigned, or a reassignment (previous_technician and reason set); shown to the office only. */
export interface AssignmentTimelineEntry {
    type: 'assignment';
    id: number;
    technician: { id: number; name: string };
    previous_technician: { id: number; name: string } | null;
    reason: string | null;
    /** Why the Manager assigned above the Technician's maximum tasks. */
    override_reason: string | null;
    actor: { id: number; name: string };
    at: string;
}

export type TimelineEntry = StatusTimelineEntry | ClassificationTimelineEntry | AssignmentTimelineEntry | CommentTimelineEntry;

/** A Complaint's current Assignment, as the office sees it. */
export interface CurrentAssignment {
    id: number;
    technician: { id: number; name: string };
    assigned_by: { id: number; name: string };
    assigned_at: string;
    override_reason: string | null;
}

/** One ranked Technician for a Complaint (GET /complaints/{id}/technician-recommendations), best first. */
export interface TechnicianRecommendation {
    rank: number;
    technician: { id: number; name: string; type: TechnicianType; company_name: string | null };
    availability: Exclude<Availability, 'on_leave'>;
    skill_match: boolean;
    active_tasks: number;
    max_tasks: number;
    /** At or above maximum tasks: assigning needs override_capacity and an override_reason. */
    at_capacity: boolean;
    same_block: boolean;
    home_block: { id: number; code: string; name: string };
    /** Already assigned this Complaint. */
    current_assignee: boolean;
    reasons: string[];
    warnings: string[];
}

/**
 * A photo served by GET /attachments/{id}: report photos to everyone who sees the Complaint in full;
 * Before and After photos to those who may see their Maintenance Record.
 */
export interface EvidencePhoto {
    id: number;
    type: 'report_photo' | 'before_photo' | 'after_photo';
    /** Set on Before and After photos: the Maintenance Record they belong to. */
    maintenance_record_id: number | null;
    mime: string;
    size_bytes: number;
    width: number;
    height: number;
    uploaded_by: { id: number; name: string };
    /** Same-origin path; the session cookie authorises it. */
    url: string;
}

/** Limits the API enforces on report photos, repeated so the form can warn before sending. */
export const photoLimits = { count: 5, megabytes: 10, types: ['image/jpeg', 'image/png', 'image/webp'] } as const;

/** A Complaint as its reporter or the office opens it (GET /complaints/{id}); a Follower gets a ComplaintSummary. */
export interface ComplaintDetail extends Complaint {
    description: string;
    urgency: Urgency;
    affected_units: AffectedUnits;
    essential_service_down: boolean;
    priority_score: number;
    priority_breakdown: Record<'urgency' | 'safety_risk' | 'affected_units' | 'location_importance' | 'essential_service', number>;
    priority_rule_version: number;
    initial_priority: Priority;
    /** When a Manager pressed Begin review; null while Reported. */
    reviewed_at: string | null;
    /** Sent back with workflow actions, so a change made meanwhile by someone else is refused with 409. */
    version: number;
    /** The office also sees the Category's default priority. */
    category: { id: number; name: string; default_priority?: Priority } | null;
    evidence: EvidencePhoto[];
    /** Status changes and the comments this viewer may read, oldest first. */
    timeline: TimelineEntry[];
    /** Office only. Cycle 1 starts at submission; each Resident Return starts another. */
    repair_cycles?: { number: number; started_at: string }[];
    /** Office only: whose work it is now; null before the first Assignment. */
    assignment?: CurrentAssignment | null;
    /** Staff only: when the Technician first started the work; null until then. */
    started_at?: string | null;
    /** Technicians only: whether they hold the current Assignment, so may comment. */
    assigned_to_you?: boolean;
    /** Technicians only: theirs, and Assigned or Returned. */
    can_start_work?: boolean;
    /** Technicians only: theirs, and In Progress. */
    can_complete_work?: boolean;
    /** Every attempt the viewer may see, oldest first: staff get the full record, the reporter what was repaired. */
    maintenance_records: (MaintenanceRecord | MaintenanceRecordSummary)[];
    /** The office and the assigned Technician; only the office gets the email. */
    reporter?: { id: number; name: string; email?: string; unit: { id: number; code: string } | null };
}

/** A Complaint's Category name, or how to say it has none yet. */
export function categoryName(category: { name: string } | null): string {
    return category?.name ?? 'Not yet classified';
}

/** GET /complaints/{id} answers in full for the reporter and the office, and with a summary for a Follower. */
export function isFullComplaint(complaint: ComplaintDetail | ComplaintSummary): complaint is ComplaintDetail {
    return 'description' in complaint;
}

/** Follow or unfollow another Resident's Common Area Complaint. */
export async function setFollowing(complaintId: number, follow: boolean): Promise<void> {
    await ensureCsrfCookie();
    await (follow ? api.post(`/complaints/${complaintId}/follow`) : api.delete(`/complaints/${complaintId}/follow`));
}

/**
 * A fresh Idempotency-Key for one Report Complaint form: 32 hex characters. Uses getRandomValues,
 * not randomUUID, because a phone opening the app over plain http on the local network is not a
 * secure context and has no randomUUID.
 */
export function newSubmissionKey(): string {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** One of a Technician's Complaints on their task list. */
export interface TechnicianTask extends Complaint {
    assigned_at: string;
}

/** GET /technician/tasks: New (Assigned), In progress, Returned and Completed. */
export type TechnicianTaskGroups = Record<'new' | 'in_progress' | 'returned' | 'completed', TechnicianTask[]>;

/** A Maintenance Record as the reporter sees it once the Complaint is Resolved: what was repaired, and its photos. */
export interface MaintenanceRecordSummary {
    id: number;
    attempt: number;
    repair_result: string;
    photo_ids: number[];
    submitted_at: string;
}

/** A Maintenance Record as staff see it: the office every one, a Technician their own. */
export interface MaintenanceRecord extends MaintenanceRecordSummary {
    repair_cycle: number;
    technician: { id: number; name: string };
    parts_used: string | null;
    notes: string | null;
    /** amount is the line's total in MYR, as a decimal string. */
    expenses: { description: string; quantity: string; amount: string }[];
    expense_total: string;
    verification_outcome: string | null;
}

export function isStaffRecord(record: MaintenanceRecord | MaintenanceRecordSummary): record is MaintenanceRecord {
    return 'technician' in record;
}

export const photoTypeLabel: Record<EvidencePhoto['type'], string> = { report_photo: 'Report', before_photo: 'Before', after_photo: 'After' };
