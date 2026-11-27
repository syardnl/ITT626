import { useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName } from '../../components/TextField';
import {
    api, complaintStatusLabel, ensureCsrfCookie, formatDateTime, priorityLabel, roleLabel, toApiError,
    type ApiError, type CommentTimelineEntry, type CommentVisibility, type TimelineEntry,
} from '../../lib/api';

interface Props {
    complaintId: number;
    timeline: TimelineEntry[];
    /** Who may add a comment here: the reporter (Public only), a Manager (Public or Internal), or nobody. */
    commenting: 'reporter' | 'manager' | null;
    onCommented: (entry: CommentTimelineEntry) => void;
}

/** The Complaint's history, oldest first, with a comment box for those who may post. */
export function ComplaintTimeline({ complaintId, timeline, commenting, onCommented }: Props) {
    return (
        <section aria-labelledby="timeline-heading">
            <h2 id="timeline-heading" className="text-lg font-semibold">Timeline</h2>
            <ol className="mt-2 space-y-4 border-l-2 border-slate-200 pl-4">
                {timeline.map((entry) => (
                    <li key={`${entry.type}-${entry.id}`}>
                        {entry.type === 'status' ? (
                            <>
                                <p className="font-medium">{complaintStatusLabel[entry.to_status]}</p>
                                <p className="text-sm text-slate-600">{formatDateTime(entry.at)}{entry.actor && ` · ${entry.actor.name}`}</p>
                                {entry.reason && <p className="text-sm">{entry.reason}</p>}
                            </>
                        ) : entry.type === 'classification' ? (
                            <>
                                <p className="font-medium">
                                    Classified: {entry.to_category.name} · {priorityLabel[entry.to_priority]} priority
                                </p>
                                <p className="text-sm text-slate-600">
                                    {formatDateTime(entry.at)} · {entry.actor.name}
                                    {entry.from_priority !== entry.to_priority && ` · Priority was ${priorityLabel[entry.from_priority]}`}
                                    {entry.from_category && entry.from_category.id !== entry.to_category.id && ` · Category was ${entry.from_category.name}`}
                                </p>
                                {entry.reason && <p className="text-sm">{entry.reason}</p>}
                            </>
                        ) : (
                            <div className={`rounded-md p-3 ${entry.visibility === 'internal' ? 'border border-amber-300 bg-amber-50' : 'border border-slate-200 bg-white'}`}>
                                <p className="text-sm text-slate-600">
                                    <span className="font-medium text-slate-900">{entry.author.name}</span>
                                    {entry.author.role && ` (${roleLabel[entry.author.role]})`} · {formatDateTime(entry.at)}
                                    {entry.visibility === 'internal' && <span className="ml-2 rounded bg-amber-200 px-2 py-0.5 text-xs font-medium text-amber-900">Internal: staff only</span>}
                                </p>
                                <p className="mt-1 whitespace-pre-line">{entry.body}</p>
                            </div>
                        )}
                    </li>
                ))}
            </ol>
            {commenting && <CommentBox complaintId={complaintId} canPostInternal={commenting === 'manager'} onCommented={onCommented} />}
        </section>
    );
}

function CommentBox({ complaintId, canPostInternal, onCommented }: { complaintId: number; canPostInternal: boolean; onCommented: Props['onCommented'] }) {
    const [body, setBody] = useState('');
    const [visibility, setVisibility] = useState<CommentVisibility>('public');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const bodyError = error?.errors.body?.[0];

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const response = await api.post<{ data: CommentTimelineEntry }>(`/complaints/${complaintId}/comments`, { body, visibility });
            onCommented(response.data.data);
            setBody('');
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} noValidate className="mt-6 space-y-3">
            {error && !bodyError && <Alert kind="error">{error.errors.visibility?.[0] ?? error.message}</Alert>}
            <div className="space-y-1">
                <label htmlFor="comment" className="block text-sm font-medium">Add a comment</label>
                <textarea
                    id="comment" rows={3} value={body} onChange={(e) => setBody(e.target.value)} maxLength={2000}
                    aria-invalid={bodyError ? true : undefined}
                    aria-describedby={bodyError ? 'comment-error comment-hint' : 'comment-hint'}
                    className={`${inputClassName} py-2`}
                />
                {bodyError && <p id="comment-error" className="text-sm text-red-700">{bodyError}</p>}
                <p id="comment-hint" className="text-sm text-slate-600">
                    Up to 2000 characters. Comments cannot be edited or deleted{canPostInternal ? '.' : ', and the management office will see this one.'}
                </p>
            </div>
            {canPostInternal && (
                <fieldset className="space-y-1">
                    <legend className="text-sm font-medium">Who can see it?</legend>
                    <label className="flex min-h-11 items-center gap-3">
                        <input type="radio" name="visibility" checked={visibility === 'public'} onChange={() => setVisibility('public')} />
                        Public: the reporter and staff
                    </label>
                    <label className="flex min-h-11 items-center gap-3">
                        <input type="radio" name="visibility" checked={visibility === 'internal'} onChange={() => setVisibility('internal')} />
                        Internal: staff only
                    </label>
                </fieldset>
            )}
            <SubmitButton busy={busy} label="Post comment" busyLabel="Posting…" />
        </form>
    );
}
