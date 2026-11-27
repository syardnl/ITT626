import { useEffect, useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName } from '../../components/TextField';
import {
    api, ensureCsrfCookie, priorities, priorityLabel, toApiError,
    type ApiError, type Category, type ComplaintDetail, type ComplaintStatus, type Priority,
} from '../../lib/api';

/** Where a Manager may still confirm or change the Category and Priority: ComplaintStatus::CLASSIFIABLE on the server. */
const classifiable: ComplaintStatus[] = ['under_review', 'assigned', 'in_progress', 'pending_verification', 'returned'];

const breakdownLabel: Record<keyof ComplaintDetail['priority_breakdown'], string> = {
    urgency: 'Urgency',
    safety_risk: 'Safety risk',
    affected_units: 'Affected Units',
    location_importance: 'Location importance',
    essential_service: 'Essential Service down',
};

interface Props {
    complaint: ComplaintDetail;
    onChanged: (complaint: ComplaintDetail) => void;
}

/** The Manager's Begin review button and Classification form on the Complaint detail page. */
export function ReviewPanel({ complaint, onChanged }: Props) {
    if (complaint.status === 'reported') return <BeginReview complaint={complaint} onChanged={onChanged} />;
    if (classifiable.includes(complaint.status)) return <Classification complaint={complaint} onChanged={onChanged} />;
    return null;
}

function BeginReview({ complaint, onChanged }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    async function begin() {
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const response = await api.post<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/review`, { version: complaint.version });
            onChanged(response.data.data);
        } catch (caught) {
            setError(toApiError(caught));
            setBusy(false);
        }
    }

    return (
        <section aria-labelledby="review-heading" className="space-y-3 rounded-md border border-blue-200 bg-blue-50 p-4">
            <h2 id="review-heading" className="text-lg font-semibold">Review</h2>
            <p className="text-sm">Nobody has reviewed this Complaint yet. Begin review to confirm its Category and Priority.</p>
            {error && <Alert kind="error">{error.message}{error.status === 409 && ' Reload the page to see the latest.'}</Alert>}
            <button
                type="button" onClick={begin} disabled={busy}
                className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60"
            >
                {busy ? 'Starting review…' : 'Begin review'}
            </button>
        </section>
    );
}

function Classification({ complaint, onChanged }: Props) {
    const [categories, setCategories] = useState<Category[] | null>(null);
    const [categoryId, setCategoryId] = useState<string>(complaint.category ? String(complaint.category.id) : '');
    const [priority, setPriority] = useState<Priority>(complaint.priority);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        api.get<{ data: Category[] }>('/categories').then((response) => setCategories(response.data.data))
            .catch((caught) => setError(toApiError(caught)));
    }, []);

    // A new Priority other than the suggested one needs a reason; keeping an override already explained does not.
    const needsReason = priority !== complaint.initial_priority && priority !== complaint.priority;
    // /categories lists active Categories only; a retired current one comes from the Complaint itself.
    const chosenCategory = categories?.find((category) => String(category.id) === categoryId)
        ?? (complaint.category && String(complaint.category.id) === categoryId ? complaint.category : undefined);
    const fieldError = (field: string) => error?.errors[field]?.[0];
    const inlineFields = ['category_id', 'priority', 'priority_reason'];
    const showsInline = !!error && inlineFields.some((field) => error.errors[field]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setSaved(false);
        try {
            await ensureCsrfCookie();
            const response = await api.patch<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/classification`, {
                category_id: categoryId === '' ? null : Number(categoryId),
                priority,
                priority_reason: needsReason ? reason : null,
                version: complaint.version,
            });
            onChanged(response.data.data);
            setReason('');
            setSaved(true);
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section aria-labelledby="classification-heading" className="space-y-4 rounded-md border border-slate-200 bg-white p-4">
            <h2 id="classification-heading" className="text-lg font-semibold">Classification</h2>

            <div>
                <h3 className="text-sm font-medium">Priority Score: {complaint.priority_score} of 100</h3>
                <dl className="mt-1 grid grid-cols-2 gap-x-4 text-sm sm:grid-cols-3">
                    {(Object.keys(breakdownLabel) as (keyof typeof breakdownLabel)[]).map((input) => (
                        <div key={input} className="flex justify-between gap-2 border-b border-slate-100 py-1">
                            <dt className="text-slate-600">{breakdownLabel[input]}</dt>
                            <dd>+{complaint.priority_breakdown[input]}</dd>
                        </div>
                    ))}
                </dl>
                <p className="mt-2 text-sm">
                    Suggested Priority: <span className="font-medium">{priorityLabel[complaint.initial_priority]}</span>
                    {chosenCategory?.default_priority && <> · {chosenCategory.name} default: <span className="font-medium">{priorityLabel[chosenCategory.default_priority]}</span></>}
                </p>
            </div>

            <form onSubmit={submit} noValidate className="space-y-4">
                {error && !showsInline && <Alert kind="error">{error.message}{error.status === 409 && ' Reload the page to see the latest.'}</Alert>}
                {saved && <Alert kind="success">Classification saved.</Alert>}

                <div className="space-y-1">
                    <label htmlFor="category" className="block text-sm font-medium">Category</label>
                    <select
                        id="category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}
                        aria-invalid={fieldError('category_id') ? true : undefined}
                        aria-describedby={fieldError('category_id') ? 'category-error' : undefined}
                        className={inputClassName}
                    >
                        <option value="">Choose a Category</option>
                        {categories?.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                        {/* /categories lists active ones only; a Category retired since it was chosen can still be confirmed. */}
                        {categories && complaint.category && !categories.some((category) => category.id === complaint.category?.id) && (
                            <option value={complaint.category.id}>{complaint.category.name} (retired)</option>
                        )}
                    </select>
                    {fieldError('category_id') && <p id="category-error" className="text-sm text-red-700">{fieldError('category_id')}</p>}
                </div>

                <fieldset className="space-y-1">
                    <legend className="text-sm font-medium">Priority</legend>
                    <div className="flex flex-wrap gap-x-5">
                        {priorities.map((level) => (
                            <label key={level} className="flex min-h-11 items-center gap-2">
                                <input type="radio" name="priority" className="size-5" checked={priority === level} onChange={() => setPriority(level)} />
                                {priorityLabel[level]}{level === complaint.initial_priority && ' (suggested)'}
                            </label>
                        ))}
                    </div>
                    {fieldError('priority') && <p className="text-sm text-red-700">{fieldError('priority')}</p>}
                </fieldset>

                {needsReason && (
                    <div className="space-y-1">
                        <label htmlFor="priority-reason" className="block text-sm font-medium">Reason for changing the Priority</label>
                        <textarea
                            id="priority-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000}
                            aria-invalid={fieldError('priority_reason') ? true : undefined}
                            aria-describedby={fieldError('priority_reason') ? 'priority-reason-error' : 'priority-reason-hint'}
                            className={`${inputClassName} py-2`}
                        />
                        {fieldError('priority_reason')
                            ? <p id="priority-reason-error" className="text-sm text-red-700">{fieldError('priority_reason')}</p>
                            : <p id="priority-reason-hint" className="text-sm text-slate-600">Required because it differs from the suggested {priorityLabel[complaint.initial_priority]}. Only staff see it.</p>}
                    </div>
                )}

                <div className="sm:max-w-xs">
                    <SubmitButton busy={busy} label="Save classification" busyLabel="Saving…" />
                </div>
            </form>
        </section>
    );
}
