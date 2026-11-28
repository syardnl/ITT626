import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { api, ensureCsrfCookie, formatDateTime, toApiError, type ApiError, type ComplaintDetail } from '../../lib/api';
import { CompleteWorkForm } from './CompleteWorkForm';

interface Props {
    complaint: ComplaintDetail;
    onChanged: (complaint: ComplaintDetail) => void;
}

/** The assigned Technician's work: Start work (Assigned or Returned → In Progress), then the Complete work form. */
export function TechnicianWorkPanel({ complaint, onChanged }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    if (complaint.reassigned_from_you) {
        return (
            <Alert kind="warning">
                This work was reassigned to another Technician. You can still read it and your own repair records, but you cannot act on it or comment.
            </Alert>
        );
    }
    if (!complaint.assigned_to_you) return null;
    const startable = complaint.can_start_work === true;

    async function start() {
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const response = await api.post<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/start-work`, { version: complaint.version });
            onChanged(response.data.data);
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section aria-labelledby="work-heading" className="space-y-3 rounded-md border border-blue-200 bg-blue-50 p-4">
            <h2 id="work-heading" className="text-lg font-semibold">Your work</h2>
            {complaint.started_at && <p className="text-sm">You started this work {formatDateTime(complaint.started_at)}.</p>}
            {startable && (
                <p className="text-sm">
                    {complaint.status === 'returned' ? 'This was sent back to you. Start work again when you are on site.' : 'Start work when you begin the repair.'}
                </p>
            )}
            {error && <Alert kind="error">{error.message}{[403, 404, 409].includes(error.status) && ' Reload the page to see the latest.'}</Alert>}
            {startable && (
                <button
                    type="button" onClick={start} disabled={busy}
                    className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60"
                >
                    {busy ? 'Starting…' : 'Start work'}
                </button>
            )}
            {complaint.can_complete_work && <CompleteWorkForm complaint={complaint} onCompleted={onChanged} />}
            {complaint.status === 'pending_verification' && <p className="text-sm">Sent to the management office for verification.</p>}
        </section>
    );
}
