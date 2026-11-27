import { useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { inputClassName } from '../../components/TextField';
import { api, ensureCsrfCookie, formatDateTime, toApiError, type ApiError, type ComplaintDetail, type Rating } from '../../lib/api';
import { PhotoGrid, recordPhotos } from './MaintenanceRecords';

interface Props {
    complaint: ComplaintDetail;
    onChanged: (complaint: ComplaintDetail) => void;
}

const scores = [1, 2, 3, 4, 5] as const;

/**
 * The reporter's answer to a Resolved Complaint: the approved repair with its Before and After
 * photos, then Confirm (with an optional Rating) or Return with a reason (a Resident Return).
 */
export function ConfirmResolutionPanel({ complaint, onChanged }: Props) {
    const [returning, setReturning] = useState(false);
    const [score, setScore] = useState<number | null>(null);
    const [feedback, setFeedback] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    if (!complaint.can_confirm_resolution) return null;

    const record = complaint.maintenance_records.at(-1);
    const photos = record ? recordPhotos(complaint, record) : [];
    const fieldError = (field: string) => error?.errors[field]?.[0];
    const shownElsewhere = ['reason', 'rating', 'feedback'].some((field) => fieldError(field));

    async function send(event: FormEvent, decision: 'confirm' | 'return') {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const body = decision === 'confirm'
                ? { decision, rating: score, feedback: score !== null && feedback ? feedback : null, version: complaint.version }
                : { decision, reason, version: complaint.version };
            const response = await api.post<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/confirm-resolution`, body);
            onChanged(response.data.data);
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section aria-labelledby="confirm-heading" className="space-y-4 rounded-md border border-green-200 bg-green-50 p-4">
            <h2 id="confirm-heading" className="text-lg font-semibold">Is the problem fixed?</h2>
            <p className="text-sm">
                The management office approved the repair{complaint.resolved_at && ` on ${formatDateTime(complaint.resolved_at)}`}.
                Please check it, then confirm or tell us it is not fixed. If you do not answer within 7 days, the Complaint closes by itself.
            </p>

            {record && (
                <div className="space-y-3 rounded-md bg-white p-3">
                    <div>
                        <h3 className="text-sm font-medium">What was repaired</h3>
                        <p className="whitespace-pre-line">{record.repair_result}</p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {([['before_photo', 'Before'], ['after_photo', 'After']] as const).map(([type, label]) => {
                            const shown = photos.filter((photo) => photo.type === type);
                            return (
                                <div key={type}>
                                    <h3 className="text-sm font-medium">{label}</h3>
                                    {shown.length > 0
                                        ? <PhotoGrid photos={shown} attempt={record.attempt} />
                                        : <p className="text-sm text-slate-600">No {label} photos.</p>}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {error && !shownElsewhere && <Alert kind="error">{error.message}{[403, 404, 409].includes(error.status) && ' Reload the page to see the latest.'}</Alert>}

            {returning ? (
                <form onSubmit={(event) => send(event, 'return')} noValidate className="space-y-3">
                    <div className="space-y-1">
                        <label htmlFor="resident-return-reason" className="block text-sm font-medium">What is still wrong? <span aria-hidden="true">*</span></label>
                        <textarea
                            id="resident-return-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000}
                            aria-invalid={fieldError('reason') ? true : undefined}
                            aria-describedby={fieldError('reason') ? 'resident-return-reason-error' : 'resident-return-reason-hint'}
                            className={`${inputClassName} py-2`}
                        />
                        {fieldError('reason')
                            ? <p id="resident-return-reason-error" className="text-sm text-red-700">{fieldError('reason')}</p>
                            : <p id="resident-return-reason-hint" className="text-sm text-slate-600">The same Technician will come back to look at it.</p>}
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <button type="submit" disabled={busy} className="min-h-11 rounded-md bg-amber-700 px-5 font-medium text-white hover:bg-amber-800 focus:outline-2 focus:outline-offset-2 focus:outline-amber-600 disabled:opacity-60">
                            {busy ? 'Sending…' : 'Send it back'}
                        </button>
                        <button type="button" onClick={() => { setReturning(false); setError(null); }} disabled={busy} className="min-h-11 rounded-md border border-slate-300 bg-white px-4 font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60">
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <form onSubmit={(event) => send(event, 'confirm')} noValidate className="space-y-3">
                    <fieldset>
                        <legend className="text-sm font-medium">How was the repair? (optional)</legend>
                        <div className="mt-1 flex flex-wrap gap-2">
                            {scores.map((value) => (
                                <label key={value} className={`flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-md border px-3 font-medium focus-within:outline-2 focus-within:outline-blue-600 ${score === value ? 'border-green-700 bg-green-700 text-white' : 'border-slate-300 bg-white hover:bg-slate-50'}`}>
                                    <input type="radio" name="rating" value={value} checked={score === value} onChange={() => setScore(value)} className="sr-only" />
                                    {value}<span className="sr-only"> out of 5</span>
                                </label>
                            ))}
                            {score !== null && (
                                <button type="button" onClick={() => setScore(null)} className="min-h-11 px-2 text-sm text-blue-700 underline">Clear</button>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-slate-600">1 is poor, 5 is excellent. The management office sees your rating; the Technician does not.</p>
                        {fieldError('rating') && <p className="text-sm text-red-700">{fieldError('rating')}</p>}
                    </fieldset>
                    {/* Feedback is kept with the Rating, so it is offered once a score is chosen. */}
                    {score !== null && <div className="space-y-1">
                        <label htmlFor="rating-feedback" className="block text-sm font-medium">Anything to add? (optional)</label>
                        <textarea
                            id="rating-feedback" rows={2} value={feedback} onChange={(e) => setFeedback(e.target.value)} maxLength={1000}
                            aria-invalid={fieldError('feedback') ? true : undefined}
                            className={`${inputClassName} py-2`}
                        />
                        {fieldError('feedback') && <p className="text-sm text-red-700">{fieldError('feedback')}</p>}
                    </div>}
                    <div className="flex flex-wrap gap-3">
                        <button type="submit" disabled={busy} className="min-h-11 rounded-md bg-green-700 px-5 font-medium text-white hover:bg-green-800 focus:outline-2 focus:outline-offset-2 focus:outline-green-600 disabled:opacity-60">
                            {busy ? 'Confirming…' : 'Yes, it is fixed'}
                        </button>
                        <button type="button" onClick={() => { setReturning(true); setError(null); }} disabled={busy} className="min-h-11 rounded-md border border-amber-600 bg-white px-4 font-medium text-amber-900 hover:bg-amber-50 focus:outline-2 focus:outline-offset-2 focus:outline-amber-600 disabled:opacity-60">
                            No, it is not fixed
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}

/** The Rating given at confirmation: the reporter's own, or the office reading it. */
export function RatingGiven({ rating, byYou }: { rating: Rating; byYou: boolean }) {
    return (
        <section aria-labelledby="rating-heading" className="rounded-md border border-slate-200 bg-white p-4">
            <h2 id="rating-heading" className="text-lg font-semibold">{byYou ? 'Your rating' : 'Resident rating'}</h2>
            <p className="mt-1"><span className="font-medium">{rating.score} out of 5</span> · {formatDateTime(rating.rated_at)}</p>
            {rating.feedback && <p className="mt-1 whitespace-pre-line">{rating.feedback}</p>}
        </section>
    );
}
