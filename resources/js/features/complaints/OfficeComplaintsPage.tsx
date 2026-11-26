import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import { inputClassName } from '../../components/TextField';
import {
    api, categoryName, complaintStatusLabel, formatDateTime, priorities, priorityLabel, toApiError,
    type Category, type Complaint, type ComplaintStatus, type LocationNode, type Paginated,
} from '../../lib/api';

const sorts = [
    { value: '', label: 'Newest first' },
    { value: 'created_at', label: 'Oldest first' },
    { value: '-priority', label: 'Most urgent Priority first' },
    { value: 'priority', label: 'Least urgent Priority first' },
    { value: '-reference_no', label: 'Reference, newest first' },
];

interface Filters {
    q: string;
    status: string;
    priority: string;
    category: string;
    block: string;
    safety_risk: string;
    sort: string;
}

const noFilters: Filters = { q: '', status: '', priority: '', category: '', block: '', safety_risk: '', sort: '' };

/** Every Complaint, read-only, for the office (Manager or Administrator). Detail links go under `basePath`. */
export function OfficeComplaintsPage({ basePath }: { basePath: string }) {
    const [filters, setFilters] = useState<Filters>(noFilters);
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Paginated<Complaint> | null>(null);
    const [categories, setCategories] = useState<Category[]>([]);
    const [blocks, setBlocks] = useState<LocationNode[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [optionsError, setOptionsError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: Category[] }>('/categories').then((r) => setCategories(r.data.data)).catch((e) => setOptionsError(toApiError(e).message));
        api.get<{ data: LocationNode[] }>('/locations').then((r) => setBlocks(r.data.data.filter((node) => node.type === 'block')))
            .catch((e) => setOptionsError(toApiError(e).message));
    }, []);

    useEffect(() => {
        // A slower answer to an earlier search must not replace a newer one.
        let current = true;
        const timer = setTimeout(() => {
            const params = Object.fromEntries(Object.entries({ ...filters, q: filters.q.trim(), page: String(page) }).filter(([, v]) => v !== ''));
            api.get<Paginated<Complaint>>('/complaints', { params })
                .then((response) => { if (current) { setResult(response.data); setError(null); } })
                .catch((caught) => { if (current) setError(toApiError(caught).message); });
        }, 250);
        return () => { current = false; clearTimeout(timer); };
    }, [filters, page]);

    function set<K extends keyof Filters>(key: K, value: Filters[K]) {
        setFilters((current) => ({ ...current, [key]: value }));
        setPage(1);
    }

    const select = (id: keyof Filters, label: string, options: { value: string; label: string }[]) => (
        <div className="space-y-1">
            <label htmlFor={`filter-${id}`} className="block text-sm font-medium">{label}</label>
            <select id={`filter-${id}`} value={filters[id]} onChange={(e) => set(id, e.target.value)} className={inputClassName}>
                {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
        </div>
    );

    return (
        <section>
            <h1 className="text-2xl font-semibold">Complaints</h1>

            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="space-y-1 sm:col-span-2">
                    <label htmlFor="filter-q" className="block text-sm font-medium">Search reference or title</label>
                    <input id="filter-q" type="search" value={filters.q} onChange={(e) => set('q', e.target.value)} className={inputClassName} />
                </div>
                {select('status', 'Status', [{ value: '', label: 'Any status' },
                    ...(Object.keys(complaintStatusLabel) as ComplaintStatus[]).map((s) => ({ value: s, label: complaintStatusLabel[s] }))])}
                {select('priority', 'Priority', [{ value: '', label: 'Any Priority' }, ...priorities.map((p) => ({ value: p, label: priorityLabel[p] }))])}
                {select('category', 'Category', [{ value: '', label: 'Any Category' }, { value: 'unclassified', label: 'Not yet classified' },
                    ...categories.map((c) => ({ value: String(c.id), label: c.name }))])}
                {select('block', 'Block', [{ value: '', label: 'Any Block' }, ...blocks.map((b) => ({ value: String(b.id), label: b.name }))])}
                {select('safety_risk', 'Safety risk', [{ value: '', label: 'Any' }, { value: '1', label: 'Safety risk only' }, { value: '0', label: 'No safety risk' }])}
                {select('sort', 'Sort', sorts)}
            </div>

            {optionsError && <div className="mt-4"><Alert kind="error">{optionsError}</Alert></div>}
            {error && <div className="mt-4"><Alert kind="error">{error}</Alert></div>}
            {result && <p className="mt-4 text-sm text-slate-600" role="status">{result.meta.total} Complaints</p>}

            <ul className="mt-2 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                {result?.data.map((complaint) => (
                    <li key={complaint.id}>
                        <Link to={`${basePath}/${complaint.id}`} className="block p-4 hover:bg-slate-50 focus:outline-2 focus:outline-blue-600">
                            <p className="flex flex-wrap items-center gap-2 font-medium">
                                {complaint.title}
                                {complaint.safety_risk && <span className="rounded bg-red-100 px-2 py-0.5 text-sm font-semibold text-red-800">⚠ Safety risk</span>}
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                {complaint.reference_no} · {complaint.location.name} · {categoryName(complaint.category)} · {formatDateTime(complaint.created_at)}
                            </p>
                            <p className="mt-1 text-sm">
                                <span className="font-medium">{complaintStatusLabel[complaint.status]}</span> · {priorityLabel[complaint.priority]} priority
                            </p>
                        </Link>
                    </li>
                ))}
            </ul>

            {result && result.meta.last_page > 1 && (
                <nav aria-label="Pages" className="mt-4 flex items-center gap-3">
                    <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="min-h-11 rounded-md border border-slate-300 px-4 disabled:opacity-50">Previous</button>
                    <span className="text-sm">Page {result.meta.current_page} of {result.meta.last_page}</span>
                    <button type="button" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)} className="min-h-11 rounded-md border border-slate-300 px-4 disabled:opacity-50">Next</button>
                </nav>
            )}
        </section>
    );
}
