import { useEffect, useState } from 'react';
import { Alert } from '../../components/Alert';
import { api, categoryName, complaintStatusLabel, formatDateTime, setFollowing, toApiError, type ComplaintSummary, type Paginated } from '../../lib/api';

/**
 * Open Complaints already raised at the chosen Common Area, so the Resident can follow one
 * instead of submitting a duplicate. Following never stops them submitting their own.
 */
export function OpenComplaintsHere({ locationId }: { locationId: number }) {
    const [complaints, setComplaints] = useState<ComplaintSummary[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState<number | null>(null);

    useEffect(() => {
        let current = true;
        setComplaints(null);
        setError(null);
        api.get<Paginated<ComplaintSummary>>(`/locations/${locationId}/open-complaints`)
            .then((response) => current && setComplaints(response.data.data))
            .catch((caught) => current && setError(toApiError(caught).message));
        return () => { current = false; };
    }, [locationId]);

    async function toggle(complaint: ComplaintSummary) {
        setPending(complaint.id);
        setError(null);
        try {
            await setFollowing(complaint.id, !complaint.following);
            setComplaints((list) => list?.map((item) => (item.id === complaint.id ? { ...item, following: !item.following } : item)) ?? null);
        } catch (caught) {
            setError(toApiError(caught).message);
        } finally {
            setPending(null);
        }
    }

    if (error) return <Alert kind="error">{error}</Alert>;
    if (!complaints) return <p className="text-sm text-slate-600">Checking for Complaints already raised here…</p>;
    if (complaints.length === 0) return <p className="text-sm text-slate-600">No open Complaints have been raised here yet.</p>;

    return (
        <section aria-labelledby="open-here-heading" className="rounded-md border border-amber-300 bg-amber-50 p-4">
            <h2 id="open-here-heading" className="font-semibold">Already reported here</h2>
            <p className="mt-1 text-sm">
                If one of these is your problem, follow it instead of submitting a new Complaint. You can still submit if yours is different.
            </p>
            <ul className="mt-3 space-y-3">
                {complaints.map((complaint) => (
                    <li key={complaint.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-white p-3">
                        <div>
                            <p className="font-medium">{complaint.title}</p>
                            <p className="text-sm text-slate-600">
                                {complaint.reference_no} · {categoryName(complaint.category)} · {complaintStatusLabel[complaint.status]} · {formatDateTime(complaint.created_at)}
                            </p>
                        </div>
                        {complaint.mine ? (
                            <span className="text-sm text-slate-600">You reported this</span>
                        ) : (
                            <button
                                type="button" onClick={() => toggle(complaint)} disabled={pending === complaint.id}
                                aria-pressed={complaint.following}
                                className="min-h-11 rounded-md border border-blue-700 px-4 font-medium text-blue-700 hover:bg-blue-50 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-50"
                            >
                                {complaint.following ? 'Following ✓' : 'Follow'}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
