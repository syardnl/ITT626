import { useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { inputClassName } from '../../components/TextField';
import { api, ensureCsrfCookie, isStaffRecord, toApiError, type ApiError, type ComplaintDetail } from '../../lib/api';
import { PhotoGrid, RecordDetails, recordPhotos } from './MaintenanceRecords';

interface Props {
    complaint: ComplaintDetail;
    onChanged: (complaint: ComplaintDetail) => void;
}

/** The Manager's Verify work section while Pending Verification: Before and After side by side, the record, then approve or return. */
export function VerifyWorkPanel({ complaint, onChanged }: Props) {
    const [returning, setReturning] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const record = complaint.maintenance_records.at(-1);
    if (complaint.status !== 'pending_verification' || !record || !isStaffRecord(record)) return null;

    const photos = recordPhotos(complaint, record);
    const before = photos.filter((photo) => photo.type === 'before_photo');
    const reasonError = error?.errors.reason?.[0];

    async function send(decision: 'approve' | 'return', event?: FormEvent) {
        event?.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const response = await api.post<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/verify`, {
                decision, reason: decision === 'return' ? reason : null, version: complaint.version,
            });
            onChanged(response.data.data);
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section aria-labelledby="verify-heading" className="space-y-4 rounded-md border border-blue-200 bg-blue-50 p-4">
            <h2 id="verify-heading" className="text-lg font-semibold">Verify work</h2>
            <p className="text-sm">{record.technician.name} completed Maintenance Record {record.attempt}. Check the repair, then approve it or return it.</p>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <h3 className="text-sm font-medium">Before</h3>
                    {before.length > 0
                        ? <PhotoGrid photos={before} attempt={record.attempt} />
                        : <p className="text-sm text-slate-600">No Before photos.</p>}
                </div>
                <div>
                    <h3 className="text-sm font-medium">After</h3>
                    <PhotoGrid photos={photos.filter((photo) => photo.type === 'after_photo')} attempt={record.attempt} />
                </div>
            </div>

            <div className="space-y-2 rounded-md bg-white p-3"><RecordDetails record={record} /></div>

            {error && !reasonError && <Alert kind="error">{error.message}{[403, 404, 409].includes(error.status) && ' Reload the page to see the latest.'}</Alert>}

            {returning ? (
                <form onSubmit={(event) => send('return', event)} noValidate className="space-y-3">
                    <div className="space-y-1">
                        <label htmlFor="return-reason" className="block text-sm font-medium">Why are you returning the work? <span aria-hidden="true">*</span></label>
                        <textarea
                            id="return-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000}
                            aria-invalid={reasonError ? true : undefined}
                            aria-describedby={reasonError ? 'return-reason-error' : 'return-reason-hint'}
                            className={`${inputClassName} py-2`}
                        />
                        {reasonError
                            ? <p id="return-reason-error" className="text-sm text-red-700">{reasonError}</p>
                            : <p id="return-reason-hint" className="text-sm text-slate-600">{record.technician.name} sees this. The reporter does not.</p>}
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <button type="submit" disabled={busy} className="min-h-11 rounded-md bg-amber-700 px-5 font-medium text-white hover:bg-amber-800 focus:outline-2 focus:outline-offset-2 focus:outline-amber-600 disabled:opacity-60">
                            {busy ? 'Returning…' : `Return to ${record.technician.name}`}
                        </button>
                        <button type="button" onClick={() => { setReturning(false); setError(null); }} className="min-h-11 rounded-md border border-slate-300 bg-white px-4 font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap gap-3">
                    <button type="button" onClick={() => send('approve')} disabled={busy} className="min-h-11 rounded-md bg-green-700 px-5 font-medium text-white hover:bg-green-800 focus:outline-2 focus:outline-offset-2 focus:outline-green-600 disabled:opacity-60">
                        {busy ? 'Approving…' : 'Approve: mark Resolved'}
                    </button>
                    <button type="button" onClick={() => setReturning(true)} className="min-h-11 rounded-md border border-amber-600 bg-white px-4 font-medium text-amber-900 hover:bg-amber-50 focus:outline-2 focus:outline-offset-2 focus:outline-amber-600">
                        Return the work
                    </button>
                </div>
            )}
        </section>
    );
}
