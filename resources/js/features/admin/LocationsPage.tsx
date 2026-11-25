import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName, TextField } from '../../components/TextField';
import {
    api, childTypesUnder, ensureCsrfCookie, locationTypeLabel, toApiError,
    type AdminLocation, type ApiError, type LocationImportance, type LocationType, type Paginated,
} from '../../lib/api';

type ParentKey = number | 'root';

const importanceTone: Record<LocationImportance, string> = {
    normal: 'bg-slate-100 text-slate-700',
    important: 'bg-amber-100 text-amber-900',
    essential: 'bg-red-100 text-red-900',
};

function Badge({ className, children }: { className: string; children: string }) {
    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${className}`}>{children}</span>;
}

function LocationBadges({ location }: { location: AdminLocation }) {
    return (
        <span className="flex flex-wrap gap-1">
            <Badge className="bg-blue-50 text-blue-900">{locationTypeLabel[location.type]}</Badge>
            {location.type === 'common_area' && (
                <Badge className={importanceTone[location.importance]}>{location.importance}</Badge>
            )}
            {location.is_shared_area && <Badge className="bg-green-100 text-green-900">Shared Area</Badge>}
            {!location.active && <Badge className="bg-slate-300 text-slate-900">Inactive</Badge>}
        </span>
    );
}

export function LocationsPage() {
    const [children, setChildren] = useState<Partial<Record<ParentKey, AdminLocation[]>>>({});
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    const [selected, setSelected] = useState<AdminLocation | null>(null);
    const [search, setSearch] = useState('');
    const [results, setResults] = useState<AdminLocation[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);

    const loadLevel = useCallback(async (parent: ParentKey) => {
        try {
            const res = await api.get<Paginated<AdminLocation>>('/admin/locations', { params: { parent, per_page: 200 } });
            setChildren((current) => ({ ...current, [parent]: res.data.data }));
        } catch (e) {
            setLoadError(toApiError(e).message);
        }
    }, []);

    useEffect(() => { void loadLevel('root'); }, [loadLevel]);

    function toggle(location: AdminLocation) {
        const next = new Set(expanded);
        if (next.has(location.id)) {
            next.delete(location.id);
        } else {
            next.add(location.id);
            if (!children[location.id]) void loadLevel(location.id);
        }
        setExpanded(next);
    }

    async function onSearch(event: FormEvent) {
        event.preventDefault();
        if (!search.trim()) return setResults(null);
        setSearching(true);
        setLoadError(null);
        try {
            const res = await api.get<Paginated<AdminLocation>>('/admin/locations', { params: { q: search.trim(), per_page: 50 } });
            setResults(res.data.data);
        } catch (e) {
            setLoadError(toApiError(e).message);
        } finally {
            setSearching(false);
        }
    }

    /** After an edit, refresh the level showing this Location and keep it selected. */
    async function onEdited(location: AdminLocation) {
        setSelected(location);
        await loadLevel(location.parent_id ?? 'root');
    }

    /** After adding, show the new Location under its parent and keep the parent selected for the next one. */
    async function onAdded(created: AdminLocation, parent: AdminLocation | null) {
        const levels: ParentKey[] = [created.parent_id ?? 'root'];
        if (parent) levels.push(parent.parent_id ?? 'root'); // the parent's row, for its child count
        await Promise.all(levels.map(loadLevel));
        if (parent) setExpanded((current) => new Set(current).add(parent.id));
    }

    function renderLevel(parent: ParentKey, depth: number) {
        const nodes = children[parent];
        if (!nodes) return <p className="px-3 py-2 text-sm text-slate-500" style={{ paddingLeft: depth * 20 + 12 }}>Loading…</p>;
        if (nodes.length === 0 && parent === 'root') {
            return <p className="p-6 text-center text-slate-600">No Locations yet. Add the first Block or Shared Area.</p>;
        }

        return (
            <ul role={parent === 'root' ? 'tree' : 'group'} aria-label={parent === 'root' ? 'Location tree' : undefined}>
                {nodes.map((location) => {
                    const hasChildren = (location.children_count ?? 0) > 0;
                    const isOpen = expanded.has(location.id);
                    return (
                        <li key={location.id} role="treeitem" aria-expanded={hasChildren ? isOpen : undefined} aria-selected={selected?.id === location.id}>
                            <div className={`flex items-center gap-1 pr-2 ${selected?.id === location.id ? 'bg-blue-50' : ''}`} style={{ paddingLeft: depth * 20 }}>
                                <button
                                    type="button" onClick={() => toggle(location)} disabled={!hasChildren}
                                    aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${location.name}`}
                                    className="flex size-11 shrink-0 items-center justify-center rounded text-slate-600 hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:invisible"
                                >
                                    {isOpen ? '▾' : '▸'}
                                </button>
                                <button
                                    type="button" onClick={() => setSelected(location)}
                                    className={`flex min-h-11 flex-1 flex-wrap items-center gap-x-2 gap-y-1 rounded px-2 text-left hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 ${location.active ? '' : 'text-slate-500'}`}
                                >
                                    <span className="font-mono text-sm">{location.code}</span>
                                    <span>{location.name}</span>
                                    <LocationBadges location={location} />
                                </button>
                            </div>
                            {hasChildren && isOpen && renderLevel(location.id, depth + 1)}
                        </li>
                    );
                })}
            </ul>
        );
    }

    return (
        <section className="space-y-6">
            <h1 className="text-2xl font-semibold md:text-3xl">Locations</h1>
            {loadError && <Alert kind="error">{loadError}</Alert>}

            <form onSubmit={onSearch} className="flex flex-wrap items-end gap-2" role="search">
                <div className="min-w-60 flex-1">
                    <TextField id="location-search" label="Find a Location by name or code" value={search} onChange={(e) => setSearch(e.target.value)} />
                </div>
                <button type="submit" disabled={searching} className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60">{searching ? 'Finding…' : 'Find'}</button>
                {results && (
                    <button type="button" onClick={() => { setResults(null); setSearch(''); }} className="min-h-11 rounded-md border border-slate-300 px-5 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">Clear</button>
                )}
            </form>

            <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                <div className="rounded-lg border border-slate-200 bg-white py-2">
                    {results ? (
                        results.length === 0 ? <p className="p-6 text-center text-slate-600">No Locations match “{search}”.</p> : (
                            <ul aria-label="Search results">
                                {results.map((location) => (
                                    <li key={location.id}>
                                        <button type="button" onClick={() => setSelected(location)} className="flex min-h-11 w-full flex-wrap items-center gap-2 px-3 text-left hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">
                                            <span className="font-mono text-sm">{location.code}</span>
                                            <span>{location.name}</span>
                                            <LocationBadges location={location} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )
                    ) : renderLevel('root', 0)}
                </div>

                <aside className="space-y-6">
                    {selected ? (
                        <EditLocation key={selected.id} location={selected} onSaved={onEdited} onClose={() => setSelected(null)} />
                    ) : (
                        <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-600">
                            Select a Location to edit it or add Locations inside it.
                        </p>
                    )}
                    <AddLocation key={`add-${selected?.id ?? 'root'}`} parent={selected} onAdded={onAdded} />
                </aside>
            </div>
        </section>
    );
}

function EditLocation({ location, onSaved, onClose }: {
    location: AdminLocation;
    onSaved: (location: AdminLocation) => Promise<void>;
    onClose: () => void;
}) {
    const [name, setName] = useState(location.name);
    const [code, setCode] = useState(location.code);
    const [importance, setImportance] = useState<LocationImportance>(location.importance);
    const [error, setError] = useState<ApiError | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function save(changes: Partial<AdminLocation>, done: string) {
        setBusy(true);
        setError(null);
        setNotice(null);
        try {
            await ensureCsrfCookie();
            const res = await api.patch<{ data: AdminLocation }>(`/admin/locations/${location.id}`, changes);
            await onSaved(res.data.data);
            setNotice(done);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        const changes: Partial<AdminLocation> = { name, code };
        if (location.type === 'common_area') changes.importance = importance;
        void save(changes, 'Changes saved.');
    }

    function toggleActive() {
        const verb = location.active ? 'Deactivate' : 'Reactivate';
        if (!window.confirm(`${verb} ${location.name}? ${location.active ? 'It will be hidden from everyone choosing a Location.' : ''}`)) return;
        void save({ active: !location.active }, `${location.name} ${location.active ? 'deactivated' : 'reactivated'}.`);
    }

    return (
        <form onSubmit={onSubmit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-4" aria-labelledby="edit-location-heading">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 id="edit-location-heading" className="text-lg font-semibold">{location.name}</h2>
                    <LocationBadges location={location} />
                </div>
                <button type="button" onClick={onClose} className="min-h-11 rounded-md px-3 text-sm text-slate-600 hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">Close</button>
            </div>
            {notice && <Alert kind="success">{notice}</Alert>}
            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}
            <TextField id="edit-name" label="Name" required value={name} onChange={(e) => setName(e.target.value)} error={error?.errors.name?.[0]} />
            <TextField id="edit-code" label="Code" required value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} error={error?.errors.code?.[0]} hint="Letters, digits and hyphens; unique across the Property." />
            {location.type === 'common_area' ? (
                <ImportanceSelect id="edit-importance" value={importance} onChange={setImportance} error={error?.errors.importance?.[0]} />
            ) : (
                <p className="text-sm text-slate-600">Importance: normal (only Common Areas carry an importance).</p>
            )}
            <SubmitButton busy={busy} label="Save changes" busyLabel="Saving…" />
            <button
                type="button" onClick={toggleActive} disabled={busy}
                className={`min-h-11 w-full rounded-md border font-medium focus:outline-2 focus:outline-blue-600 disabled:opacity-60 ${location.active ? 'border-red-300 text-red-800 hover:bg-red-50' : 'border-green-300 text-green-800 hover:bg-green-50'}`}
            >
                {location.active ? 'Deactivate' : 'Reactivate'}
            </button>
        </form>
    );
}

function AddLocation({ parent, onAdded }: {
    parent: AdminLocation | null;
    onAdded: (created: AdminLocation, parent: AdminLocation | null) => Promise<void>;
}) {
    const allowed = childTypesUnder[parent?.type ?? 'property'];
    const [type, setType] = useState<LocationType | ''>(allowed[0] ?? '');
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [importance, setImportance] = useState<LocationImportance>('normal');
    const [error, setError] = useState<ApiError | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const where = parent ? parent.name : 'the Property';
    if (allowed.length === 0) {
        return <p className="text-sm text-slate-600">Nothing can be placed inside a {parent ? locationTypeLabel[parent.type] : ''}.</p>;
    }
    if (parent && !parent.active) {
        return <p className="text-sm text-slate-600">Reactivate {parent.name} to add Locations inside it.</p>;
    }

    async function onSubmit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setNotice(null);
        try {
            await ensureCsrfCookie();
            const body: Record<string, unknown> = { type, parent_id: parent?.id ?? null, code, name };
            if (type === 'common_area') body.importance = importance;
            const res = await api.post<{ data: AdminLocation }>('/admin/locations', body);
            await onAdded(res.data.data, parent);
            setNotice(`${res.data.data.name} added.`);
            setCode('');
            setName('');
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-4" aria-labelledby="add-location-heading">
            <h2 id="add-location-heading" className="text-lg font-semibold">Add inside {where}</h2>
            {notice && <Alert kind="success">{notice}</Alert>}
            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}
            <div className="space-y-1">
                <label htmlFor="add-type" className="block text-sm font-medium">Type</label>
                <select
                    id="add-type" value={type} onChange={(e) => setType(e.target.value as LocationType)} className={inputClassName}
                    aria-invalid={error?.errors.parent_id ? true : undefined}
                    aria-describedby={error?.errors.parent_id ? 'add-type-error' : undefined}
                >
                    {allowed.map((option) => <option key={option} value={option}>{locationTypeLabel[option]}</option>)}
                </select>
                {error?.errors.parent_id && <p id="add-type-error" role="alert" className="text-sm text-red-700">{error.errors.parent_id[0]}</p>}
            </div>
            <TextField id="add-code" label="Code" required value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} error={error?.errors.code?.[0]} hint="For example A-03-02 or A-LIFT-3." />
            <TextField id="add-name" label="Name" required value={name} onChange={(e) => setName(e.target.value)} error={error?.errors.name?.[0]} />
            {type === 'common_area' && (
                <ImportanceSelect id="add-importance" value={importance} onChange={setImportance} error={error?.errors.importance?.[0]} />
            )}
            <SubmitButton busy={busy} label="Add Location" busyLabel="Adding…" />
        </form>
    );
}

function ImportanceSelect({ id, value, onChange, error }: {
    id: string; value: LocationImportance; onChange: (value: LocationImportance) => void; error?: string;
}) {
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium">Importance</label>
            <select
                id={id} value={value} onChange={(e) => onChange(e.target.value as LocationImportance)} className={inputClassName}
                aria-invalid={error ? true : undefined} aria-describedby={error ? `${id}-error` : undefined}
            >
                <option value="normal">Normal</option>
                <option value="important">Important</option>
                <option value="essential">Essential</option>
            </select>
            {error && <p id={`${id}-error`} role="alert" className="text-sm text-red-700">{error}</p>}
        </div>
    );
}
