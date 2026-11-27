import { useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName } from '../../components/TextField';
import { api, ensureCsrfCookie, newSubmissionKey, toApiError, type ApiError, type ComplaintDetail } from '../../lib/api';
import { PhotoPicker } from './PhotoPicker';

type Tag = 'before' | 'after';
interface ExpenseLine { description: string; quantity: string; amount: string }

interface Props {
    complaint: ComplaintDetail;
    onCompleted: (complaint: ComplaintDetail) => void;
}

/** The assigned Technician's Complete work form, single column for a phone on site: one Maintenance Record. */
export function CompleteWorkForm({ complaint, onCompleted }: Props) {
    const [result, setResult] = useState('');
    const [photos, setPhotos] = useState<File[]>([]);
    const [tags, setTags] = useState<Map<File, Tag>>(new Map());
    const [parts, setParts] = useState('');
    const [notes, setNotes] = useState('');
    const [expenses, setExpenses] = useState<ExpenseLine[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    // One key per form: a retry after a dropped connection cannot create a second record.
    const [submissionKey] = useState(newSubmissionKey);

    const tagOf = (photo: File): Tag => tags.get(photo) ?? 'after';
    const fieldError = (field: string) => error?.errors[field]?.[0];
    const firstError = (prefix: string) => Object.entries(error?.errors ?? {}).find(([field]) => field === prefix || field.startsWith(`${prefix}.`))?.[1][0];
    const hasFieldErrors = !!error && Object.keys(error.errors).length > 0;

    function updateLine(index: number, change: Partial<ExpenseLine>) {
        setExpenses(expenses.map((line, other) => (other === index ? { ...line, ...change } : line)));
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const form = new FormData();
            form.append('repair_result', result);
            form.append('parts_used', parts);
            form.append('notes', notes);
            form.append('version', String(complaint.version));
            photos.forEach((photo) => {
                form.append('photos[]', photo);
                form.append('photo_tags[]', tagOf(photo));
            });
            expenses.forEach((line, index) => {
                form.append(`expenses[${index}][description]`, line.description);
                form.append(`expenses[${index}][quantity]`, line.quantity);
                form.append(`expenses[${index}][amount]`, line.amount);
            });
            const response = await api.post<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/complete-work`, form, {
                headers: { 'Idempotency-Key': submissionKey },
            });
            onCompleted(response.data.data);
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} noValidate className="space-y-5">
            <h3 className="text-base font-semibold">Complete work</h3>
            {error && (
                <Alert kind="error">
                    {hasFieldErrors ? 'Please check the answers marked below.' : error.message}
                    {error.status === 409 && ' Reload the page to see the latest.'}
                </Alert>
            )}

            <div className="space-y-1">
                <label htmlFor="repair-result" className="block text-sm font-medium">What did you repair? <span aria-hidden="true">*</span></label>
                <textarea
                    id="repair-result" rows={3} value={result} onChange={(e) => setResult(e.target.value)} maxLength={2000} required
                    aria-invalid={fieldError('repair_result') ? true : undefined}
                    aria-describedby={fieldError('repair_result') ? 'repair-result-error' : undefined}
                    className={`${inputClassName} py-2`}
                />
                {fieldError('repair_result') && <p id="repair-result-error" className="text-sm text-red-700">{fieldError('repair_result')}</p>}
            </div>

            <PhotoPicker
                photos={photos}
                onChange={setPhotos}
                legend={<>Photos: at least one After photo <span aria-hidden="true">*</span></>}
                error={firstError('photos') ?? firstError('photo_tags')}
                controlsFor={(photo, index) => (
                    <label className="block text-sm">
                        <span className="sr-only">Photo {index + 1} shows</span>
                        <select
                            value={tagOf(photo)} onChange={(e) => setTags(new Map(tags).set(photo, e.target.value as Tag))}
                            className={`${inputClassName} min-h-11`}
                        >
                            <option value="before">Before</option>
                            <option value="after">After</option>
                        </select>
                    </label>
                )}
            />

            <div className="space-y-1">
                <label htmlFor="parts-used" className="block text-sm font-medium">Parts used (optional)</label>
                <textarea id="parts-used" rows={2} value={parts} onChange={(e) => setParts(e.target.value)} maxLength={1000} className={`${inputClassName} py-2`} />
                {fieldError('parts_used') && <p className="text-sm text-red-700">{fieldError('parts_used')}</p>}
            </div>

            <fieldset className="space-y-3">
                <legend className="text-sm font-medium">Expenses (optional)</legend>
                <p className="text-sm text-slate-600">One line per item. Amount is the line total in ringgit (MYR).</p>
                {expenses.map((line, index) => (
                    <div key={index} className="space-y-2 rounded-md border border-slate-200 p-3">
                        <label className="block text-sm">
                            Description
                            <input value={line.description} onChange={(e) => updateLine(index, { description: e.target.value })} maxLength={200} className={inputClassName} />
                        </label>
                        {fieldError(`expenses.${index}.description`) && <p className="text-sm text-red-700">{fieldError(`expenses.${index}.description`)}</p>}
                        <div className="grid grid-cols-2 gap-3">
                            <label className="block text-sm">
                                Quantity
                                <input inputMode="decimal" value={line.quantity} onChange={(e) => updateLine(index, { quantity: e.target.value })} className={inputClassName} />
                            </label>
                            <label className="block text-sm">
                                Amount (RM)
                                <input inputMode="decimal" value={line.amount} onChange={(e) => updateLine(index, { amount: e.target.value })} placeholder="0.00" className={inputClassName} />
                            </label>
                        </div>
                        {[fieldError(`expenses.${index}.quantity`), fieldError(`expenses.${index}.amount`)].filter(Boolean).map((message) => (
                            <p key={message} className="text-sm text-red-700">{message}</p>
                        ))}
                        <button type="button" onClick={() => setExpenses(expenses.filter((_, other) => other !== index))} className="min-h-11 text-sm text-red-700 underline">
                            Remove this line
                        </button>
                    </div>
                ))}
                {expenses.length < 20 && (
                    <button
                        type="button" onClick={() => setExpenses([...expenses, { description: '', quantity: '1', amount: '' }])}
                        className="min-h-11 rounded-md border border-slate-300 bg-white px-4 font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600"
                    >
                        Add an expense
                    </button>
                )}
            </fieldset>

            <div className="space-y-1">
                <label htmlFor="work-notes" className="block text-sm font-medium">Notes for the office (optional)</label>
                <textarea id="work-notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} className={`${inputClassName} py-2`} />
                {fieldError('notes') && <p className="text-sm text-red-700">{fieldError('notes')}</p>}
            </div>

            <SubmitButton busy={busy} label="Send for verification" busyLabel="Sending…" />
        </form>
    );
}
