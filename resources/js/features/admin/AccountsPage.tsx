import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName, TextField } from '../../components/TextField';
import { useAuth } from '../../lib/auth';
import {
    api, ensureCsrfCookie, residentTypeLabel, roleLabel, toApiError,
    type AdminLocation, type AdminUser, type ApiError, type Paginated, type ResidentType, type Role,
} from '../../lib/api';

const roles = Object.keys(roleLabel) as Role[];
const residentTypes = Object.keys(residentTypeLabel) as ResidentType[];
const filterNames = ['q', 'role', 'block', 'active'] as const;

/** Active Units and all Blocks, for the Unit picker and the Block filter. */
function useLocations() {
    const [units, setUnits] = useState<AdminLocation[]>([]);
    const [blocks, setBlocks] = useState<AdminLocation[]>([]);

    useEffect(() => {
        const list = (params: Record<string, unknown>) => api.get<Paginated<AdminLocation>>('/admin/locations', { params: { ...params, per_page: 200 } });
        void list({ type: 'unit', active: 1 }).then((res) => setUnits(res.data.data));
        void list({ type: 'block' }).then((res) => setBlocks(res.data.data));
    }, []);

    return { units, blocks };
}

function Badge({ className, children }: { className: string; children: string }) {
    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${className}`}>{children}</span>;
}

function StatusBadges({ user }: { user: AdminUser }) {
    return (
        <span className="flex flex-wrap gap-1">
            {!user.active && <Badge className="bg-slate-300 text-slate-900">Inactive</Badge>}
            {!user.password_set && <Badge className="bg-amber-100 text-amber-900">Link pending</Badge>}
        </span>
    );
}

export function AccountsPage() {
    const [params, setParams] = useSearchParams();
    const [result, setResult] = useState<Paginated<AdminUser> | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<AdminUser | 'new' | null>(null);
    // Survives the form re-mounting when a newly created account becomes the selected one.
    const [flash, setFlash] = useState<string | null>(null);
    const { units, blocks } = useLocations();

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await api.get<Paginated<AdminUser>>('/admin/users', { params: Object.fromEntries(params) });
            setResult(res.data);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setLoading(false);
        }
    }, [params]);

    useEffect(() => { void load(); }, [load]);

    function onSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const next = new URLSearchParams();
        for (const name of filterNames) {
            const value = String(form.get(name) ?? '').trim();
            if (value) next.set(name, value);
        }
        setParams(next);
    }

    function goToPage(page: number) {
        const next = new URLSearchParams(params);
        next.set('page', String(page));
        setParams(next);
    }

    function choose(next: AdminUser | 'new' | null) {
        setFlash(null);
        setSelected(next);
    }

    async function onSaved(user: AdminUser, message: string) {
        setFlash(message);
        setSelected(user);
        await load();
    }

    const select = (name: string, label: string, options: [string, string][]) => (
        <div className="space-y-1">
            <label htmlFor={`filter-${name}`} className="block text-sm font-medium">{label}</label>
            <select id={`filter-${name}`} name={name} defaultValue={params.get(name) ?? ''} className={inputClassName}>
                <option value="">Any</option>
                {options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
        </div>
    );

    return (
        <section className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-2xl font-semibold md:text-3xl">Accounts</h1>
                <button
                    type="button" onClick={() => choose('new')}
                    className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600"
                >
                    New account
                </button>
            </div>

            <form onSubmit={onSearch} role="search" aria-label="Filter accounts" className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
                <div className="lg:col-span-2">
                    <TextField id="filter-q" name="q" label="Name or email" defaultValue={params.get('q') ?? ''} error={error?.errors.q?.[0]} />
                </div>
                {select('role', 'Role', roles.map((r) => [r, roleLabel[r]]))}
                {select('block', 'Block (Residents)', blocks.map((b) => [String(b.id), b.name]))}
                {select('active', 'Status', [['1', 'Active'], ['0', 'Inactive']])}
                <div className="flex gap-2 sm:col-span-2 lg:col-span-5">
                    <button type="submit" className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">Search</button>
                    <button type="button" onClick={() => setParams(new URLSearchParams())} className="min-h-11 rounded-md border border-slate-300 px-5 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">Clear</button>
                </div>
            </form>

            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}

            <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                <div className="space-y-3">
                    {loading && <p role="status" className="text-slate-600">Loading accounts…</p>}
                    {!loading && result && result.data.length === 0 && (
                        <p className="rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-600">No accounts match these filters.</p>
                    )}
                    {!loading && result && result.data.length > 0 && (
                        <>
                            <ul aria-label="Accounts" className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                                {result.data.map((user) => {
                                    const isSelected = selected !== 'new' && selected?.id === user.id;
                                    return (
                                        <li key={user.id}>
                                            <button
                                                type="button" onClick={() => choose(user)} aria-current={isSelected ? 'true' : undefined}
                                                className={`flex min-h-11 w-full flex-col gap-0.5 px-3 py-2 text-left hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 ${isSelected ? 'bg-blue-50' : ''}`}
                                            >
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className={`font-medium ${user.active ? '' : 'text-slate-500'}`}>{user.name}</span>
                                                    <Badge className="bg-blue-50 text-blue-900">{roleLabel[user.role]}</Badge>
                                                    <StatusBadges user={user} />
                                                </span>
                                                <span className="text-sm text-slate-600">
                                                    {user.email}{user.unit && ` · ${user.unit.code}`}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                            <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                                <p className="text-slate-600">Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} accounts</p>
                                <div className="flex gap-2">
                                    <button type="button" disabled={result.meta.current_page <= 1} onClick={() => goToPage(result.meta.current_page - 1)} className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-50">Previous</button>
                                    <button type="button" disabled={result.meta.current_page >= result.meta.last_page} onClick={() => goToPage(result.meta.current_page + 1)} className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-50">Next</button>
                                </div>
                            </nav>
                        </>
                    )}
                </div>

                <aside>
                    {selected === 'new' && <AccountForm key="new" units={units} onSaved={onSaved} onClose={() => choose(null)} />}
                    {selected && selected !== 'new' && <AccountForm key={selected.id} user={selected} units={units} initialNotice={flash} onSaved={onSaved} onClose={() => choose(null)} />}
                    {!selected && (
                        <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-600">
                            Select an account to edit it, or choose “New account”.
                        </p>
                    )}
                </aside>
            </div>
        </section>
    );
}

/** Create a new account (no `user`) or edit, deactivate and resend the link for an existing one. */
function AccountForm({ user, units, initialNotice = null, onSaved, onClose }: {
    user?: AdminUser;
    units: AdminLocation[];
    initialNotice?: string | null;
    onSaved: (user: AdminUser, message: string) => Promise<void>;
    onClose: () => void;
}) {
    const { account } = useAuth();
    const [name, setName] = useState(user?.name ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [role, setRole] = useState<Role>(user?.role ?? 'resident');
    const [unitId, setUnitId] = useState(user?.unit ? String(user.unit.id) : '');
    const [residentType, setResidentType] = useState<ResidentType | ''>(user?.resident_type ?? '');
    const [error, setError] = useState<ApiError | null>(null);
    const [notice, setNotice] = useState<string | null>(initialNotice);
    const [busy, setBusy] = useState(false);

    const isSelf = user !== undefined && user.id === account?.id;
    const heading = user ? user.name : 'New account';
    // The current Unit stays selectable even if it is no longer active.
    const unitOptions = user?.unit && !units.some((u) => u.id === user.unit!.id) ? [{ ...user.unit, active: false }, ...units] : units;

    async function run(action: () => Promise<{ user?: AdminUser; message: string }>) {
        setBusy(true);
        setError(null);
        setNotice(null);
        try {
            await ensureCsrfCookie();
            const { user: saved, message } = await action();
            setNotice(message);
            if (saved) await onSaved(saved, message);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        const body: Record<string, unknown> = { name, email, role };
        if (role === 'resident') {
            body.unit_id = unitId ? Number(unitId) : null;
            body.resident_type = residentType || null;
        }
        void run(async () => {
            if (user) {
                const res = await api.patch<{ data: AdminUser }>(`/admin/users/${user.id}`, body);
                return { user: res.data.data, message: 'Changes saved.' };
            }
            const res = await api.post<{ data: AdminUser }>('/admin/users', body);
            return { user: res.data.data, message: `Account created. A set-password link has been emailed to ${res.data.data.email}.` };
        });
    }

    function toggleActive() {
        if (!user) return;
        const verb = user.active ? 'Deactivate' : 'Reactivate';
        const consequence = user.active ? ' They will be signed out everywhere and cannot sign in.' : '';
        if (!window.confirm(`${verb} ${user.name}?${consequence}`)) return;
        void run(async () => {
            const res = await api.patch<{ data: AdminUser }>(`/admin/users/${user.id}`, { active: !user.active });
            return { user: res.data.data, message: `${user.name} ${user.active ? 'deactivated' : 'reactivated'}.` };
        });
    }

    function resendInvite() {
        if (!user) return;
        void run(async () => {
            const res = await api.post<{ message: string }>(`/admin/users/${user.id}/resend-invite`);
            return { message: res.data.message };
        });
    }

    const fieldError = (field: string) => error?.errors[field]?.[0];
    const selectField = (id: string, field: string, label: string, value: string, onChange: (value: string) => void, options: [string, string][], placeholder?: string) => (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium">{label}</label>
            <select
                id={id} value={value} onChange={(e) => onChange(e.target.value)} className={inputClassName}
                aria-invalid={fieldError(field) ? true : undefined} aria-describedby={fieldError(field) ? `${id}-error` : undefined}
            >
                {placeholder && <option value="">{placeholder}</option>}
                {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
            </select>
            {fieldError(field) && <p id={`${id}-error`} role="alert" className="text-sm text-red-700">{fieldError(field)}</p>}
        </div>
    );

    return (
        <form onSubmit={onSubmit} aria-labelledby="account-form-heading" className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 id="account-form-heading" className="text-lg font-semibold">{heading}</h2>
                    {user && <StatusBadges user={user} />}
                </div>
                <button type="button" onClick={onClose} className="min-h-11 rounded-md px-3 text-sm text-slate-600 hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">Close</button>
            </div>
            {notice && <Alert kind="success">{notice}</Alert>}
            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}

            <TextField id="account-name" label="Name" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} error={fieldError('name')} />
            <TextField id="account-email" label="Email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} error={fieldError('email')} />
            {selectField('account-role', 'role', 'Role', role, (v) => setRole(v as Role), roles.map((r) => [r, roleLabel[r]]))}
            {isSelf && <p className="text-sm text-slate-600">You cannot change your own role or deactivate your own account.</p>}

            {role === 'resident' ? (
                <>
                    {selectField('account-unit', 'unit_id', 'Unit', unitId, setUnitId,
                        unitOptions.map((u) => [String(u.id), `${u.code} · ${u.name}${u.active ? '' : ' (inactive)'}`]), 'Choose a Unit')}
                    {selectField('account-resident-type', 'resident_type', 'Resident Type', residentType, (v) => setResidentType(v as ResidentType),
                        residentTypes.map((t) => [t, residentTypeLabel[t]]), 'Choose a type')}
                </>
            ) : (
                <p className="text-sm text-slate-600">Staff accounts are not linked to a Unit.{user?.unit ? ' Saving will unlink the current Unit.' : ''}</p>
            )}

            <SubmitButton busy={busy} label={user ? 'Save changes' : 'Create account'} busyLabel="Saving…" />

            {user && (
                <div className="grid gap-2 sm:grid-cols-2">
                    {!user.password_set && user.active && (
                        <button type="button" onClick={resendInvite} disabled={busy} className="min-h-11 rounded-md border border-slate-300 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-60">
                            Resend set-password link
                        </button>
                    )}
                    {!isSelf && (
                        <button
                            type="button" onClick={toggleActive} disabled={busy}
                            className={`min-h-11 rounded-md border font-medium focus:outline-2 focus:outline-blue-600 disabled:opacity-60 ${user.active ? 'border-red-300 text-red-800 hover:bg-red-50' : 'border-green-300 text-green-800 hover:bg-green-50'}`}
                        >
                            {user.active ? 'Deactivate' : 'Reactivate'}
                        </button>
                    )}
                </div>
            )}
        </form>
    );
}
