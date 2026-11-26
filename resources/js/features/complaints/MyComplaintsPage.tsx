import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Alert } from '../../components/Alert';
import {
    api, categoryName, complaintStatusLabel, formatDateTime, priorityLabel, toApiError,
    type Complaint, type ComplaintSummary, type Paginated,
} from '../../lib/api';

type Scope = 'mine' | 'following';

const tabs: { scope: Scope; label: string }[] = [
    { scope: 'mine', label: 'Reported by me' },
    { scope: 'following', label: 'Following' },
];

/** A Resident's own Complaints, or (`?scope=following`) the ones they follow until each is Closed. */
export function MyComplaintsPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const scope: Scope = searchParams.get('scope') === 'following' ? 'following' : 'mine';
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Paginated<Complaint | ComplaintSummary> | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let current = true;
        setResult(null);
        setError(null);
        api.get<Paginated<Complaint | ComplaintSummary>>('/complaints', { params: { scope, page } })
            .then((response) => current && setResult(response.data))
            .catch((caught) => current && setError(toApiError(caught).message));
        return () => { current = false; };
    }, [scope, page]);

    function choose(next: Scope) {
        setPage(1);
        setSearchParams(next === 'mine' ? {} : { scope: next });
    }

    return (
        <section>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">My Complaints</h1>
                <Link to="/resident/complaints/new" className="inline-flex min-h-11 items-center rounded-md bg-blue-700 px-4 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    Report a problem
                </Link>
            </div>

            <nav aria-label="Which Complaints" className="mt-4 flex gap-2">
                {tabs.map((tab) => (
                    <button
                        key={tab.scope} type="button" onClick={() => choose(tab.scope)} aria-current={scope === tab.scope ? 'page' : undefined}
                        className={`min-h-11 rounded-md px-4 font-medium focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 ${scope === tab.scope ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white hover:bg-slate-50'}`}
                    >
                        {tab.label}
                    </button>
                ))}
            </nav>

            {error && <div className="mt-4"><Alert kind="error">{error}</Alert></div>}
            {result && result.data.length === 0 && (
                <p className="mt-6 text-slate-600">
                    {scope === 'mine'
                        ? 'You have not reported any problems yet.'
                        : 'You are not following any Complaints. When you report a problem at a Common Area, you can follow one already raised there.'}
                </p>
            )}

            {result && result.data.length > 0 && (
                <ul className="mt-6 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                    {result.data.map((complaint) => (
                        <li key={complaint.id}>
                            <Link to={`/resident/complaints/${complaint.id}`} className="block p-4 hover:bg-slate-50 focus:outline-2 focus:outline-blue-600">
                                <p className="font-medium">{complaint.title}</p>
                                <p className="mt-1 text-sm text-slate-600">
                                    {complaint.reference_no}
                                    {'location' in complaint ? ` · ${complaint.location.name}` : ` · ${categoryName(complaint.category)}`}
                                    {' · '}{formatDateTime(complaint.created_at)}
                                </p>
                                <p className="mt-1 text-sm">
                                    <span className="font-medium">{complaintStatusLabel[complaint.status]}</span>
                                    {'priority' in complaint && ` · ${priorityLabel[complaint.priority]} priority`}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

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
