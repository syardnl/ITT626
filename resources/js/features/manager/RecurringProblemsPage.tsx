import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import { api, complaintStatusLabel, ensureCsrfCookie, formatDateTime, toApiError, type ComplaintStatus } from '../../lib/api';

interface RecurringFlag {
    id: number;
    status: 'open' | 'acknowledged' | 'dismissed';
    active: boolean;
    location: { id: number; code: string; name: string };
    category: { id: number; name: string };
    complaint_count: number;
    complaints: { id: number; reference_no: string; title: string; status: ComplaintStatus; created_at: string }[];
    first_reported_at: string;
    last_reported_at: string;
    decided_by: { id: number; name: string } | null;
    decided_at: string | null;
    reason: string | null;
    superseded_at: string | null;
    raised_at: string;
}

const statusLook: Record<RecurringFlag['status'], { label: string; className: string }> = {
    open: { label: 'Needs a decision', className: 'bg-amber-100 text-amber-900' },
    acknowledged: { label: 'Acknowledged', className: 'bg-blue-100 text-blue-900' },
    dismissed: { label: 'Dismissed', className: 'bg-slate-100 text-slate-800' },
};

/** Recurring Problems (design §6.4): flags waiting for a decision, acknowledged ones, then the history. */
export function RecurringProblemsPage() {
    const [flags, setFlags] = useState<{ data: RecurringFlag[]; history: RecurringFlag[] } | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(() => {
        api.get<{ data: RecurringFlag[]; history: RecurringFlag[] }>('/recurring-flags')
            .then((response) => setFlags(response.data))
            .catch((caught) => setError(toApiError(caught).message));
    }, []);
    useEffect(load, [load]);

    return (
        <section className="mx-auto max-w-3xl space-y-6">
            <div>
                <h1 className="text-2xl font-semibold md:text-3xl">Recurring problems</h1>
                <p className="mt-1 text-slate-600">
                    Three or more Complaints of one Category at the same Location within 90 days. Checked every night.
                </p>
            </div>
            {error && <Alert kind="error">{error}</Alert>}
            {!flags && !error && <p className="text-slate-600">Loading…</p>}
            {flags && flags.data.length === 0 && <p className="text-slate-600">No recurring problems right now.</p>}
            {flags && flags.data.length > 0 && (
                <ul className="space-y-4">
                    {flags.data.map((flag) => <li key={flag.id}><FlagCard flag={flag} onDecided={load} /></li>)}
                </ul>
            )}
            {flags && flags.history.length > 0 && (
                <details className="rounded-md border border-slate-200 bg-white p-4">
                    <summary className="cursor-pointer font-medium">History ({flags.history.length})</summary>
                    <ul className="mt-4 space-y-4">
                        {flags.history.map((flag) => <li key={flag.id}><FlagCard flag={flag} /></li>)}
                    </ul>
                </details>
            )}
        </section>
    );
}

function FlagCard({ flag, onDecided }: { flag: RecurringFlag; onDecided?: () => void }) {
    const look = statusLook[flag.status];

    return (
        <article className="rounded-md border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-semibold">{flag.category.name} at {flag.location.name} ({flag.location.code})</h2>
                <span className={`rounded px-2 py-0.5 text-sm font-medium ${look.className}`}>{look.label}</span>
                {!flag.active && <span className="rounded bg-slate-100 px-2 py-0.5 text-sm">Now below three in 90 days</span>}
                {flag.superseded_at && <span className="rounded bg-slate-100 px-2 py-0.5 text-sm">Replaced {formatDateTime(flag.superseded_at)}</span>}
            </div>
            <p className="mt-1 text-sm text-slate-600">
                {flag.complaint_count} Complaints · first {formatDateTime(flag.first_reported_at)} · latest {formatDateTime(flag.last_reported_at)}
            </p>
            <ul className="mt-3 space-y-1 text-sm">
                {flag.complaints.map((complaint) => (
                    <li key={complaint.id}>
                        <Link to={`/manager/complaints/${complaint.id}`} className="text-blue-800 underline hover:text-blue-950 focus:outline-2 focus:outline-blue-600">
                            {complaint.reference_no}
                        </Link>
                        {` · ${complaint.title} · ${complaintStatusLabel[complaint.status]} · ${formatDateTime(complaint.created_at)}`}
                    </li>
                ))}
            </ul>
            {flag.decided_by && (
                <p className="mt-3 text-sm">
                    <span className="font-medium">{flag.status === 'acknowledged' ? 'Acknowledged' : 'Dismissed'} by {flag.decided_by.name}</span>
                    {flag.decided_at && ` on ${formatDateTime(flag.decided_at)}`}: {flag.reason}
                </p>
            )}
            {flag.status === 'open' && !flag.superseded_at && onDecided && <DecisionForm flag={flag} onDecided={onDecided} />}
        </article>
    );
}

function DecisionForm({ flag, onDecided }: { flag: RecurringFlag; onDecided: () => void }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const id = `reason-${flag.id}`;

    async function decide(decision: 'acknowledge' | 'dismiss') {
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            await api.post(`/recurring-flags/${flag.id}/${decision}`, { reason });
            onDecided();
        } catch (caught) {
            const apiError = toApiError(caught);
            setError(apiError.errors?.reason?.[0] ?? apiError.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mt-4 space-y-2 border-t border-slate-200 pt-4">
            <label htmlFor={id} className="block text-sm font-medium">Reason for your decision</label>
            <textarea
                id={id} value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={1000}
                className="w-full rounded-md border border-slate-300 p-2 focus:outline-2 focus:outline-blue-600"
            />
            {error && <Alert kind="error">{error}</Alert>}
            <div className="flex flex-wrap gap-2">
                <button
                    type="button" disabled={busy} onClick={() => void decide('acknowledge')}
                    className="min-h-11 rounded-md bg-blue-700 px-4 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60"
                >
                    Acknowledge: it is a real problem
                </button>
                <button
                    type="button" disabled={busy} onClick={() => void decide('dismiss')}
                    className="min-h-11 rounded-md border border-slate-300 px-4 font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-60"
                >
                    Dismiss
                </button>
            </div>
        </div>
    );
}
