import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { Alert } from '../../components/Alert';
import {
    affectedUnitsLabel, api, categoryName, complaintStatusLabel, formatDateTime, isFullComplaint, priorityLabel, setFollowing, toApiError, urgencyLabel,
    type ComplaintDetail, type ComplaintSummary,
} from '../../lib/api';

export function ComplaintDetailPage() {
    const { id } = useParams();
    const justReported = (useLocation().state as { justReported?: string } | null)?.justReported;
    const [complaint, setComplaint] = useState<ComplaintDetail | ComplaintSummary | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: ComplaintDetail | ComplaintSummary }>(`/complaints/${id}`)
            .then((response) => setComplaint(response.data.data))
            .catch((caught) => {
                const apiError = toApiError(caught);
                setError(apiError.status === 404 ? 'This Complaint does not exist or you cannot view it.' : apiError.message);
            });
    }, [id]);

    if (error) return <Alert kind="error">{error}</Alert>;
    if (!complaint) return <p>Loading…</p>;
    if (!isFullComplaint(complaint)) return <FollowedComplaint complaint={complaint} />;

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
                <div><dt className="text-sm text-slate-600">Category</dt><dd>{categoryName(complaint.category)}</dd></div>
                <div><dt className="text-sm text-slate-600">Urgency</dt><dd>{urgencyLabel[complaint.urgency]}</dd></div>
                <div><dt className="text-sm text-slate-600">Affected</dt><dd>{affectedUnitsLabel[complaint.affected_units]}</dd></div>
                <div><dt className="text-sm text-slate-600">Essential service down</dt><dd>{complaint.essential_service_down ? 'Yes' : 'No'}</dd></div>
                <div><dt className="text-sm text-slate-600">Reported</dt><dd>{formatDateTime(complaint.created_at)}</dd></div>
                {complaint.reporter && (
                    <div className="sm:col-span-2">
                        <dt className="text-sm text-slate-600">Reported by</dt>
                        <dd>
                            {complaint.reporter.name} · {complaint.reporter.email}
                            {complaint.reporter.unit && ` · Unit ${complaint.reporter.unit.code}`}
                        </dd>
                    </div>
                )}
            </dl>

            <section>
                <h2 className="text-lg font-semibold">Description</h2>
                <p className="mt-2 whitespace-pre-line">{complaint.description}</p>
            </section>

            {complaint.evidence.length > 0 && (
                <section>
                    <h2 className="text-lg font-semibold">Photos</h2>
                    <ul className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {complaint.evidence.map((photo, index) => (
                            <li key={photo.id}>
                                <a href={photo.url} target="_blank" rel="noreferrer" className="block focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                                    <img
                                        src={photo.url} alt={`Photo ${index + 1} of ${complaint.evidence.length}`} loading="lazy"
                                        width={photo.width} height={photo.height} className="aspect-square w-full rounded-md object-cover"
                                    />
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

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

/** What a Follower sees: the summary only, never the reporter, description or photos. */
function FollowedComplaint({ complaint }: { complaint: ComplaintSummary }) {
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function unfollow() {
        setBusy(true);
        setError(null);
        try {
            await setFollowing(complaint.id, false);
            navigate('/resident/complaints?scope=following');
        } catch (caught) {
            setError(toApiError(caught).message);
            setBusy(false);
        }
    }

    return (
        <article className="mx-auto max-w-2xl space-y-6">
            <header>
                <p className="text-sm text-slate-600">{complaint.reference_no} · You are following this Complaint</p>
                <h1 className="text-2xl font-semibold">{complaint.title}</h1>
            </header>

            {error && <Alert kind="error">{error}</Alert>}

            <dl className="grid gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-3">
                <div><dt className="text-sm text-slate-600">Status</dt><dd className="font-medium">{complaintStatusLabel[complaint.status]}</dd></div>
                <div><dt className="text-sm text-slate-600">Category</dt><dd>{categoryName(complaint.category)}</dd></div>
                <div><dt className="text-sm text-slate-600">Reported</dt><dd>{formatDateTime(complaint.created_at)}</dd></div>
            </dl>

            <p className="text-sm text-slate-600">Another Resident reported this, so only its progress is shown here.</p>

            <div className="flex flex-wrap gap-3">
                <button
                    type="button" onClick={unfollow} disabled={busy}
                    className="min-h-11 rounded-md border border-slate-300 bg-white px-4 font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-50"
                >
                    {busy ? 'Unfollowing…' : 'Unfollow'}
                </button>
                <Link to="/resident/complaints?scope=following" className="inline-flex min-h-11 items-center px-2 text-blue-700 underline">Back to Following</Link>
            </div>
        </article>
    );
}

