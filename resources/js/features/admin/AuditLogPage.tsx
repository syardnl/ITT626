import { useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { Alert } from '../../components/Alert';
import { inputClassName, TextField } from '../../components/TextField';
import { api, formatDateTime, toApiError, type ApiError, type Paginated } from '../../lib/api';

interface AuditEntry {
    id: number;
    action: string;
    actor: { id: number; name: string; email: string } | null;
    target_type: string | null;
    target_id: number | null;
    changes: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
}

const actions: Record<string, string> = {
    'auth.signed_in': 'Signed in',
    'auth.sign_in_failed': 'Failed sign-in',
    'auth.password_set': 'Password set',
    'account.invite_sent': 'Set-password link sent',
    created: 'Created',
    updated: 'Updated',
    deactivated: 'Deactivated',
    reactivated: 'Reactivated',
    'complaint.reviewed': 'Review begun',
    'complaint.classified': 'Classified',
};

const filterNames = ['actor', 'action', 'target_type', 'from', 'to'] as const;

function describe(value: unknown): string {
    if (value && typeof value === 'object' && 'from' in value && 'to' in value) {
        const { from, to } = value as { from: unknown; to: unknown };
        return from === null ? String(to) : `${String(from)} → ${String(to)}`;
    }
    return String(value);
}

function Details({ entry }: { entry: AuditEntry }) {
    const fields = { ...(entry.metadata ?? {}), ...(entry.changes ?? {}) };
    const names = Object.keys(fields);
    if (names.length === 0) return <span className="text-slate-500">—</span>;

    return (
        <dl className="space-y-0.5">
            {names.map((name) => (
                <div key={name} className="flex gap-1">
                    <dt className="font-medium">{name}:</dt>
                    <dd className="break-all">{describe(fields[name])}</dd>
                </div>
            ))}
        </dl>
    );
}

export function AuditLogPage() {
    const [params, setParams] = useSearchParams();
    const [result, setResult] = useState<Paginated<AuditEntry> | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        setError(null);
        api.get<Paginated<AuditEntry>>('/admin/audit-logs', { params: Object.fromEntries(params) })
            .then((res) => setResult(res.data))
            .catch((e) => setError(toApiError(e)))
            .finally(() => setLoading(false));
    }, [params]);

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

    const fieldError = (name: string) => error?.errors[name]?.[0];
    const filterField = (name: (typeof filterNames)[number], label: string, extra: { type?: string; placeholder?: string } = {}) => (
        <TextField id={`filter-${name}`} name={name} label={label} defaultValue={params.get(name) ?? ''} error={fieldError(name)} {...extra} />
    );

    return (
        <section className="space-y-6">
            <h1 className="text-2xl font-semibold md:text-3xl">Audit log</h1>

            <form onSubmit={onSearch} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6" aria-label="Filter audit log">
                <div className="lg:col-span-2">{filterField('actor', 'Actor (name or email)')}</div>
                <div className="space-y-1">
                    <label htmlFor="filter-action" className="block text-sm font-medium">Action</label>
                    <select id="filter-action" name="action" defaultValue={params.get('action') ?? ''} className={inputClassName}>
                        <option value="">Any</option>
                        {Object.entries(actions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </div>
                {filterField('target_type', 'Target type', { placeholder: 'e.g. user' })}
                {filterField('from', 'From', { type: 'date' })}
                {filterField('to', 'To', { type: 'date' })}
                <div className="flex gap-2 sm:col-span-2 lg:col-span-6">
                    <button type="submit" className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                        Search
                    </button>
                    <button type="button" onClick={() => setParams(new URLSearchParams())} className="min-h-11 rounded-md border border-slate-300 px-5 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600">
                        Clear
                    </button>
                </div>
            </form>

            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}

            {loading && <p role="status" className="text-slate-600">Loading entries…</p>}

            {!loading && result && result.data.length === 0 && (
                <p className="rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-600">No entries match these filters.</p>
            )}

            {!loading && result && result.data.length > 0 && (
                <>
                    <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Audit entries, newest first</caption>
                            <thead className="bg-slate-100 text-slate-700">
                                <tr>
                                    <th scope="col" className="px-3 py-2">Time</th>
                                    <th scope="col" className="px-3 py-2">Action</th>
                                    <th scope="col" className="px-3 py-2">Actor</th>
                                    <th scope="col" className="px-3 py-2">Target</th>
                                    <th scope="col" className="px-3 py-2">Details</th>
                                    <th scope="col" className="px-3 py-2">IP address</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200">
                                {result.data.map((entry) => (
                                    <tr key={entry.id} className="align-top">
                                        <td className="whitespace-nowrap px-3 py-2">{formatDateTime(entry.created_at)}</td>
                                        <td className="px-3 py-2">{actions[entry.action] ?? entry.action}</td>
                                        <td className="px-3 py-2">
                                            {entry.actor ? (
                                                <><span className="font-medium">{entry.actor.name}</span><br /><span className="text-slate-600">{entry.actor.email}</span></>
                                            ) : <span className="text-slate-500">Unknown / system</span>}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2">{entry.target_type ? `${entry.target_type} #${entry.target_id}` : '—'}</td>
                                        <td className="px-3 py-2"><Details entry={entry} /></td>
                                        <td className="whitespace-nowrap px-3 py-2 font-mono text-xs">{entry.ip_address ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                        <p className="text-slate-600">
                            Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} entries
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button" disabled={result.meta.current_page <= 1} onClick={() => goToPage(result.meta.current_page - 1)}
                                className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <button
                                type="button" disabled={result.meta.current_page >= result.meta.last_page} onClick={() => goToPage(result.meta.current_page + 1)}
                                className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    </nav>
                </>
            )}
        </section>
    );
}
