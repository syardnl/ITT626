import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import { api, complaintStatusLabel, formatDateTime, priorityLabel, toApiError, type Complaint, type Paginated } from '../../lib/api';

export function MyComplaintsPage() {
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Paginated<Complaint> | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<Paginated<Complaint>>('/complaints', { params: { page } })
            .then((response) => setResult(response.data))
            .catch((caught) => setError(toApiError(caught).message));
    }, [page]);

    return (
        <section>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">My Complaints</h1>
                <Link to="/resident/complaints/new" className="inline-flex min-h-11 items-center rounded-md bg-blue-700 px-4 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    Report a problem
                </Link>
            </div>

            {error && <div className="mt-4"><Alert kind="error">{error}</Alert></div>}
            {result && result.data.length === 0 && <p className="mt-6 text-slate-600">You have not reported any problems yet.</p>}

            <ul className="mt-6 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                {result?.data.map((complaint) => (
                    <li key={complaint.id}>
                        <Link to={`/resident/complaints/${complaint.id}`} className="block p-4 hover:bg-slate-50 focus:outline-2 focus:outline-blue-600">
                            <p className="font-medium">{complaint.title}</p>
                            <p className="mt-1 text-sm text-slate-600">
                                {complaint.reference_no} · {complaint.location.name} · {formatDateTime(complaint.created_at)}
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
