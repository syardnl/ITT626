import { useEffect, useState } from 'react';
import { useLocation, useParams } from 'react-router';
import { Alert } from '../../components/Alert';
import {
    affectedUnitsLabel, api, complaintStatusLabel, formatDateTime, priorityLabel, toApiError, urgencyLabel,
    type ComplaintDetail,
} from '../../lib/api';

export function ComplaintDetailPage() {
    const { id } = useParams();
    const justReported = (useLocation().state as { justReported?: string } | null)?.justReported;
    const [complaint, setComplaint] = useState<ComplaintDetail | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: ComplaintDetail }>(`/complaints/${id}`)
            .then((response) => setComplaint(response.data.data))
            .catch((caught) => {
                const apiError = toApiError(caught);
                setError(apiError.status === 404 ? 'This Complaint does not exist or you cannot view it.' : apiError.message);
            });
    }, [id]);

    if (error) return <Alert kind="error">{error}</Alert>;
    if (!complaint) return <p>Loading…</p>;

    return (
        <article className="mx-auto max-w-2xl space-y-6">
            {justReported && <Alert kind="success">Thank you. Your Complaint {justReported} has been sent to the management office.</Alert>}

            <header>
                <p className="text-sm text-slate-600">{complaint.reference_no}</p>
                <h1 className="text-2xl font-semibold">{complaint.title}</h1>
                <p className="mt-2">
                    <span className="font-medium">{complaintStatusLabel[complaint.status]}</span> · {priorityLabel[complaint.priority]} priority
                    {complaint.safety_risk && <span className="ml-2 rounded bg-red-100 px-2 py-0.5 text-sm font-medium text-red-800">Safety risk</span>}
                </p>
            </header>

            <dl className="grid gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-2">
                <div><dt className="text-sm text-slate-600">Location</dt><dd>{complaint.location.name} ({complaint.location.code})</dd></div>
                <div><dt className="text-sm text-slate-600">Category</dt><dd>{complaint.category?.name ?? 'Not yet classified'}</dd></div>
                <div><dt className="text-sm text-slate-600">Urgency</dt><dd>{urgencyLabel[complaint.urgency]}</dd></div>
                <div><dt className="text-sm text-slate-600">Affected</dt><dd>{affectedUnitsLabel[complaint.affected_units]}</dd></div>
                <div><dt className="text-sm text-slate-600">Essential service down</dt><dd>{complaint.essential_service_down ? 'Yes' : 'No'}</dd></div>
                <div><dt className="text-sm text-slate-600">Reported</dt><dd>{formatDateTime(complaint.created_at)}</dd></div>
            </dl>

            <section>
                <h2 className="text-lg font-semibold">Description</h2>
                <p className="mt-2 whitespace-pre-line">{complaint.description}</p>
            </section>

            <section>
                <h2 className="text-lg font-semibold">Timeline</h2>
                <ol className="mt-2 space-y-3 border-l-2 border-slate-200 pl-4">
                    {complaint.timeline.map((entry, index) => (
                        <li key={index}>
                            <p className="font-medium">{complaintStatusLabel[entry.to_status]}</p>
                            <p className="text-sm text-slate-600">{formatDateTime(entry.at)}{entry.actor && ` · ${entry.actor.name}`}</p>
                            {entry.reason && <p className="text-sm">{entry.reason}</p>}
                        </li>
                    ))}
                </ol>
            </section>
        </article>
    );
}
