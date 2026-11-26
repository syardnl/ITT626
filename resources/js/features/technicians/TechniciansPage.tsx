import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName, TextField } from '../../components/TextField';
import {
    api, availabilityLabel, ensureCsrfCookie, technicianTypeLabel, toApiError,
    type ApiError, type Availability, type Paginated, type Technician, type TechnicianFormOptions, type TechnicianType,
} from '../../lib/api';
import { AvailabilityBadge, Badge } from './TechnicianDetails';

const types = Object.keys(technicianTypeLabel) as TechnicianType[];
const availabilities = Object.keys(availabilityLabel) as Availability[];
const filterNames = ['skill', 'availability', 'type', 'block'] as const;
const emptyOptions: TechnicianFormOptions = { accounts: [], skills: [], blocks: [] };
type OptionsStatus = 'loading' | 'ready' | 'failed';

/** Technician profiles for Managers and Administrators: filter the team, create and edit profiles. */
export function TechniciansPage() {
    const [params, setParams] = useSearchParams();
    const [result, setResult] = useState<Paginated<Technician> | null>(null);
    const [options, setOptions] = useState<TechnicianFormOptions>(emptyOptions);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<Technician | 'new' | null>(null);
    const [flash, setFlash] = useState<string | null>(null);
    const [optionsStatus, setOptionsStatus] = useState<OptionsStatus>('loading');

    // Never rejects: a failure is shown on the page, and must not turn a successful save into an error.
    const loadOptions = useCallback(async () => {
        try {
            const res = await api.get<{ data: TechnicianFormOptions }>('/technicians/form-options');
            setOptions(res.data.data);
            setOptionsStatus('ready');
        } catch {
            setOptionsStatus('failed');
        }
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await api.get<Paginated<Technician>>('/technicians', { params: Object.fromEntries(params) });
            setResult(res.data);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setLoading(false);
        }
    }, [params]);

    useEffect(() => { void load(); }, [load]);
    useEffect(() => { void loadOptions(); }, [loadOptions]);

    function onFilter(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const next = new URLSearchParams();
        for (const name of filterNames) {
            const value = String(form.get(name) ?? '');
            if (value) next.set(name, value);
        }
        setParams(next);
    }

    function goToPage(page: number) {
        const next = new URLSearchParams(params);
        next.set('page', String(page));
        setParams(next);
    }

    function choose(next: Technician | 'new' | null) {
        setFlash(null);
        setSelected(next);
    }

    async function onSaved(technician: Technician, message: string) {
        setFlash(message);
        setSelected(technician);
        await Promise.all([load(), loadOptions()]);
    }

    const select = (name: string, label: string, choices: [string, string][]) => (
        <div className="space-y-1">
            <label htmlFor={`filter-${name}`} className="block text-sm font-medium">{label}</label>
            <select id={`filter-${name}`} name={name} defaultValue={params.get(name) ?? ''} className={inputClassName}>
                <option value="">Any</option>
                {choices.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
        </div>
    );

    return (
        <section className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-2xl font-semibold md:text-3xl">Technicians</h1>
                <button
                    type="button" onClick={() => choose('new')}
                    className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600"
                >
                    New profile
                </button>
            </div>

            {optionsStatus === 'failed' && (
                <Alert kind="error">The Skills, Blocks and Technician accounts could not be loaded, so filters and the profile form are incomplete. Reload the page to try again.</Alert>
            )}

            {/* Uncontrolled selects: re-mount when the URL or the option lists change, so they always show the filters applied. */}
            <form
                key={`${params.toString()}|${optionsStatus}`}
                onSubmit={onFilter} role="search" aria-label="Filter Technicians" className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
                {select('skill', 'Skill', options.skills.map((s) => [String(s.id), s.name]))}
                {select('availability', 'Availability', availabilities.map((a) => [a, availabilityLabel[a]]))}
                {select('type', 'Type', types.map((t) => [t, technicianTypeLabel[t]]))}
                {select('block', 'Home Block', options.blocks.map((b) => [String(b.id), `${b.code} · ${b.name}`]))}
                <div className="flex gap-2 sm:col-span-2 lg:col-span-4">
                    <button type="submit" className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">Filter</button>
                    <button type="button" onClick={() => setParams(new URLSearchParams())} className="min-h-11 rounded-md border border-slate-300 px-5 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">Clear</button>
                </div>
            </form>

            {error && <Alert kind="error">{error.message}</Alert>}

            <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                <div className="space-y-3">
                    {loading && <p role="status" className="text-slate-600">Loading Technicians…</p>}
                    {!loading && result && result.data.length === 0 && (
                        <p className="rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-600">No Technicians match these filters.</p>
                    )}
                    {!loading && result && result.data.length > 0 && (
                        <>
                            <ul aria-label="Technicians" className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                                {result.data.map((technician) => {
                                    const isSelected = selected !== 'new' && selected?.id === technician.id;
                                    return (
                                        <li key={technician.id}>
                                            <button
                                                type="button" onClick={() => choose(technician)} aria-current={isSelected ? 'true' : undefined}
                                                className={`flex min-h-11 w-full flex-col gap-0.5 px-3 py-2 text-left hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 ${isSelected ? 'bg-blue-50' : ''}`}
                                            >
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className={`font-medium ${technician.user.active ? '' : 'text-slate-500'}`}>{technician.user.name}</span>
                                                    <AvailabilityBadge availability={technician.availability} />
                                                    {technician.type === 'contractor' && <Badge className="bg-blue-50 text-blue-900">Contractor</Badge>}
                                                    {!technician.user.active && <Badge className="bg-slate-300 text-slate-900">Account inactive</Badge>}
                                                </span>
                                                <span className="text-sm text-slate-600">
                                                    {technician.skills.map((s) => s.name).join(', ')} · Block {technician.home_block.code} · up to {technician.max_tasks} tasks
                                                    {technician.company_name && ` · ${technician.company_name}`}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                            <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                                <p className="text-slate-600">Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} Technicians</p>
                                <div className="flex gap-2">
                                    <button type="button" disabled={result.meta.current_page <= 1} onClick={() => goToPage(result.meta.current_page - 1)} className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-50">Previous</button>
                                    <button type="button" disabled={result.meta.current_page >= result.meta.last_page} onClick={() => goToPage(result.meta.current_page + 1)} className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-50">Next</button>
                                </div>
                            </nav>
                        </>
                    )}
                </div>

                <aside>
                    {selected === 'new' && <ProfileForm key="new" options={options} optionsStatus={optionsStatus} onSaved={onSaved} onClose={() => choose(null)} />}
                    {selected && selected !== 'new' && (
                        <ProfileForm key={selected.id} technician={selected} options={options} optionsStatus={optionsStatus} initialNotice={flash} onSaved={onSaved} onClose={() => choose(null)} />
                    )}
                    {!selected && (
                        <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-600">
                            Select a Technician to edit their profile, or choose “New profile”.
                        </p>
                    )}
                </aside>
            </div>
        </section>
    );
}

/** Create a profile (no `technician`) or edit one. On edit only the fields that changed are sent. */
function ProfileForm({ technician, options, optionsStatus, initialNotice = null, onSaved, onClose }: {
    technician?: Technician;
    options: TechnicianFormOptions;
    optionsStatus: OptionsStatus;
    initialNotice?: string | null;
    onSaved: (technician: Technician, message: string) => Promise<void>;
    onClose: () => void;
}) {
    const initial = {
        type: technician?.type ?? 'in_house',
        company_name: technician?.company_name ?? '',
        skill_ids: technician?.skills.map((s) => s.id) ?? [],
        availability: technician?.availability ?? 'available',
        max_tasks: technician ? String(technician.max_tasks) : '5',
        home_block_id: technician ? String(technician.home_block.id) : '',
    };
    const [userId, setUserId] = useState('');
    const [type, setType] = useState<TechnicianType>(initial.type);
    const [companyName, setCompanyName] = useState(initial.company_name);
    const [skillIds, setSkillIds] = useState<number[]>(initial.skill_ids);
    const [availability, setAvailability] = useState<Availability>(initial.availability);
    const [maxTasks, setMaxTasks] = useState(initial.max_tasks);
    const [homeBlockId, setHomeBlockId] = useState(initial.home_block_id);
    const [error, setError] = useState<ApiError | null>(null);
    const [notice, setNotice] = useState<string | null>(initialNotice);
    const [busy, setBusy] = useState(false);

    // Skills and the home Block already on the profile stay listed even if since deactivated.
    const skillChoices = [...options.skills];
    technician?.skills.forEach((s) => {
        if (!skillChoices.some((c) => c.id === s.id)) skillChoices.push({ id: s.id, name: `${s.name} (inactive)` });
    });
    const blockChoices = technician && !options.blocks.some((b) => b.id === technician.home_block.id)
        ? [{ ...technician.home_block, name: `${technician.home_block.name} (inactive)` }, ...options.blocks]
        : options.blocks;

    function toggleSkill(id: number, checked: boolean) {
        setSkillIds((current) => (checked ? [...current, id] : current.filter((s) => s !== id)));
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        const fields: Record<string, unknown> = {
            type,
            skill_ids: [...skillIds].sort((a, b) => a - b),
            availability,
            max_tasks: maxTasks === '' ? null : Number(maxTasks),
            home_block_id: homeBlockId ? Number(homeBlockId) : null,
        };
        if (type === 'contractor') fields.company_name = companyName.trim() || null;

        void (async () => {
            setBusy(true);
            setError(null);
            setNotice(null);
            try {
                await ensureCsrfCookie();
                if (technician) {
                    const before: Record<string, unknown> = {
                        type: initial.type,
                        skill_ids: [...initial.skill_ids].sort((a, b) => a - b),
                        availability: initial.availability,
                        max_tasks: Number(initial.max_tasks),
                        home_block_id: Number(initial.home_block_id),
                        company_name: initial.company_name || null,
                    };
                    const changed = Object.fromEntries(
                        Object.entries(fields).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(before[key])),
                    );
                    const res = await api.patch<{ data: Technician }>(`/technicians/${technician.id}`, changed);
                    setNotice('Changes saved.');
                    await onSaved(res.data.data, 'Changes saved.');
                } else {
                    const res = await api.post<{ data: Technician }>('/technicians', { ...fields, user_id: userId ? Number(userId) : null });
                    const message = `Profile created for ${res.data.data.user.name}.`;
                    setNotice(message);
                    await onSaved(res.data.data, message);
                }
            } catch (e) {
                setError(toApiError(e));
            } finally {
                setBusy(false);
            }
        })();
    }

    const fieldError = (field: string) => error?.errors[field]?.[0];
    const skillError = fieldError('skill_ids') ?? Object.entries(error?.errors ?? {}).find(([key]) => key.startsWith('skill_ids.'))?.[1][0];
    const selectField = (id: string, field: string, label: string, value: string, onChange: (value: string) => void, choices: [string, string][], placeholder?: string) => (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium">{label}</label>
            <select
                id={id} value={value} onChange={(e) => onChange(e.target.value)} className={inputClassName}
                aria-invalid={fieldError(field) ? true : undefined} aria-describedby={fieldError(field) ? `${id}-error` : undefined}
            >
                {placeholder && <option value="">{placeholder}</option>}
                {choices.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
            </select>
            {fieldError(field) && <p id={`${id}-error`} role="alert" className="text-sm text-red-700">{fieldError(field)}</p>}
        </div>
    );

    return (
        <form onSubmit={onSubmit} aria-labelledby="profile-form-heading" className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 id="profile-form-heading" className="text-lg font-semibold">{technician ? technician.user.name : 'New Technician profile'}</h2>
                    {technician && <p className="text-sm text-slate-600">{technician.user.email}</p>}
                </div>
                <button type="button" onClick={onClose} className="min-h-11 rounded-md px-3 text-sm text-slate-600 hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">Close</button>
            </div>
            {notice && <Alert kind="success">{notice}</Alert>}
            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}

            {!technician && optionsStatus === 'loading' && <p role="status" className="text-sm text-slate-600">Loading Technician accounts…</p>}
            {!technician && optionsStatus === 'failed' && <p className="text-sm text-red-700">Technician accounts could not be loaded. Reload the page to try again.</p>}
            {!technician && optionsStatus === 'ready' && (
                options.accounts.length === 0 ? (
                    <p className="text-sm text-slate-600">Every active Technician account already has a profile. An Administrator creates Technician accounts first.</p>
                ) : (
                    selectField('profile-user', 'user_id', 'Technician account', userId, setUserId,
                        options.accounts.map((a) => [String(a.id), `${a.name} · ${a.email}`]), 'Choose an account')
                )
            )}

            {selectField('profile-type', 'type', 'Type', type, (v) => setType(v as TechnicianType), types.map((t) => [t, technicianTypeLabel[t]]))}
            {type === 'contractor' && (
                <TextField id="profile-company" label="Company name" required maxLength={150} value={companyName} onChange={(e) => setCompanyName(e.target.value)} error={fieldError('company_name')} />
            )}

            <fieldset className="space-y-2" aria-describedby={skillError ? 'profile-skills-error' : undefined}>
                <legend className="text-sm font-medium">Skills</legend>
                <div className="grid gap-1 sm:grid-cols-2">
                    {skillChoices.map((skill) => (
                        <label key={skill.id} className="flex min-h-11 items-center gap-2 rounded-md px-2 hover:bg-slate-50">
                            <input
                                type="checkbox" checked={skillIds.includes(skill.id)} onChange={(e) => toggleSkill(skill.id, e.target.checked)}
                                className="size-5 focus:outline-2 focus:outline-blue-600"
                            />
                            <span className="text-sm">{skill.name}</span>
                        </label>
                    ))}
                </div>
                {skillError && <p id="profile-skills-error" role="alert" className="text-sm text-red-700">{skillError}</p>}
            </fieldset>

            {selectField('profile-availability', 'availability', 'Availability', availability, (v) => setAvailability(v as Availability),
                availabilities.map((a) => [a, availabilityLabel[a]]))}
            <TextField
                id="profile-max-tasks" label="Maximum tasks" type="number" inputMode="numeric" min={1} max={100} step={1} required
                value={maxTasks} onChange={(e) => setMaxTasks(e.target.value)} error={fieldError('max_tasks')}
                hint="How many active tasks this Technician can hold at once."
            />
            {selectField('profile-home-block', 'home_block_id', 'Home Block', homeBlockId, setHomeBlockId,
                blockChoices.map((b) => [String(b.id), `${b.code} · ${b.name}`]), 'Choose a Block')}

            <SubmitButton busy={busy} label={technician ? 'Save changes' : 'Create profile'} busyLabel="Saving…" />
        </form>
    );
}
