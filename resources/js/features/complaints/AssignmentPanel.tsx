import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { inputClassName } from '../../components/TextField';
import {
    api, ensureCsrfCookie, formatDateTime, technicianTypeLabel, toApiError,
    type ApiError, type ComplaintDetail, type ComplaintStatus, type TechnicianRecommendation,
} from '../../lib/api';

/** Where a Manager may assign (Under Review) or reassign: ComplaintStatus::ASSIGNABLE on the server. */
const assignable: ComplaintStatus[] = ['under_review', 'assigned', 'in_progress', 'returned'];

interface Props {
    complaint: ComplaintDetail;
    onChanged: (complaint: ComplaintDetail) => void;
}

/** The Manager's view of whose work a Complaint is, with Assign Technician or Reassign. */
export function AssignmentPanel({ complaint, onChanged }: Props) {
    const [open, setOpen] = useState(false);
    const current = complaint.assignment ?? null;
    if (complaint.status === 'reported') return null;
    const canAssign = assignable.includes(complaint.status);

    return (
        <section aria-labelledby="assignment-heading" className="space-y-3 rounded-md border border-slate-200 bg-white p-4">
            <h2 id="assignment-heading" className="text-lg font-semibold">Technician</h2>
            {current ? (
                <p>
                    <span className="font-medium">{current.technician.name}</span>
                    <span className="text-sm text-slate-600"> · assigned {formatDateTime(current.assigned_at)} by {current.assigned_by.name}</span>
                </p>
            ) : (
                <p className="text-sm">No Technician assigned yet.</p>
            )}
            {canAssign && !complaint.category && <p className="text-sm text-slate-600">Classify this Complaint before assigning a Technician.</p>}
            {canAssign && complaint.category && (
                <button
                    type="button" onClick={() => setOpen(true)}
                    className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600"
                >
                    {current ? 'Reassign' : 'Assign Technician'}
                </button>
            )}
            {open && (
                <AssignDialog
                    complaint={complaint} reassigning={current !== null}
                    onClose={() => setOpen(false)}
                    onAssigned={(updated) => { setOpen(false); onChanged(updated); }}
                />
            )}
        </section>
    );
}

interface DialogProps {
    complaint: ComplaintDetail;
    reassigning: boolean;
    onClose: () => void;
    onAssigned: (complaint: ComplaintDetail) => void;
}

/** Ranked candidate cards (design §6.2) with Busy and capacity warnings, the capacity override, and a note or reason. */
function AssignDialog({ complaint, reassigning, onClose, onAssigned }: DialogProps) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [candidates, setCandidates] = useState<TechnicianRecommendation[] | null>(null);
    const [technicianId, setTechnicianId] = useState<number | null>(null);
    const [note, setNote] = useState('');
    const [reason, setReason] = useState('');
    const [override, setOverride] = useState(false);
    const [overrideReason, setOverrideReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        dialog.current?.showModal();
        api.get<{ data: TechnicianRecommendation[] }>(`/complaints/${complaint.id}/technician-recommendations`)
            .then((response) => setCandidates(response.data.data))
            .catch((caught) => setError(toApiError(caught)));
    }, [complaint.id]);

    const chosen = candidates?.find((candidate) => candidate.technician.id === technicianId);
    const fieldError = (field: string) => error?.errors[field]?.[0];
    const inlineFields = ['technician_id', 'note', 'reason', 'override_capacity', 'override_reason'];
    const showsInline = !!error && inlineFields.some((field) => error.errors[field]);
    const title = reassigning ? 'Reassign' : 'Assign Technician';

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const response = await api.post<{ data: ComplaintDetail }>(`/complaints/${complaint.id}/assignments`, {
                technician_id: technicianId,
                note: note.trim() === '' ? null : note,
                reason: reassigning ? reason : null,
                override_capacity: chosen?.at_capacity ? override : false,
                override_reason: chosen?.at_capacity && override ? overrideReason : null,
                version: complaint.version,
            });
            onAssigned(response.data.data);
        } catch (caught) {
            setError(toApiError(caught));
            setBusy(false);
        }
    }

    return (
        <dialog
            ref={dialog} onClose={onClose} aria-labelledby="assign-heading"
            className="m-auto w-full max-w-2xl rounded-md p-0 backdrop:bg-slate-900/50"
        >
            <form onSubmit={submit} noValidate className="max-h-[90vh] space-y-4 overflow-y-auto p-4 sm:p-6">
                <h2 id="assign-heading" className="text-xl font-semibold">{title}</h2>
                <p className="text-sm text-slate-600">
                    Ranked for {complaint.category?.name} at {complaint.location.name}: Available first, then matching Skill, lightest load and home Block.
                </p>

                {error && !showsInline && <Alert kind="error">{error.message}{error.status === 409 && ' Reload the page to see the latest.'}</Alert>}
                {!candidates && !error && <p>Loading Technicians…</p>}
                {candidates?.length === 0 && <p>No Technician can take new work: everyone is On leave or deactivated.</p>}

                {candidates && candidates.length > 0 && (
                    <fieldset className="space-y-2" aria-describedby={fieldError('technician_id') ? 'technician-error' : undefined}>
                        <legend className="text-sm font-medium">Choose a Technician</legend>
                        {fieldError('technician_id') && <p id="technician-error" className="text-sm text-red-700">{fieldError('technician_id')}</p>}
                        <ol className="space-y-2">
                            {candidates.map((candidate) => (
                                <li key={candidate.technician.id}>
                                    <CandidateCard
                                        candidate={candidate} checked={technicianId === candidate.technician.id}
                                        onChoose={() => { setTechnicianId(candidate.technician.id); setOverride(false); }}
                                    />
                                </li>
                            ))}
                        </ol>
                    </fieldset>
                )}

                {chosen?.at_capacity && (
                    <div className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3">
                        <label className="flex min-h-11 items-center gap-3">
                            <input type="checkbox" className="size-5" checked={override} onChange={(e) => setOverride(e.target.checked)} />
                            Assign anyway, above {chosen.technician.name}’s maximum of {chosen.max_tasks} tasks
                        </label>
                        {fieldError('override_capacity') && <p className="text-sm text-red-700">{fieldError('override_capacity')}</p>}
                        {override && (
                            <TextArea id="override-reason" label="Reason for the override" value={overrideReason} onChange={setOverrideReason}
                                error={fieldError('override_reason')} hint="Recorded in the audit log." maxLength={1000} />
                        )}
                    </div>
                )}

                {reassigning && (
                    <TextArea id="reassign-reason" label="Reason for reassigning" value={reason} onChange={setReason}
                        error={fieldError('reason')} hint="Required. Kept with the Complaint’s history; staff only." maxLength={1000} />
                )}
                <TextArea id="assign-note" label="Note for the Technician (optional)" value={note} onChange={setNote}
                    error={fieldError('note')} hint="Saved as an Internal Comment: staff only." maxLength={2000} />

                <div className="flex flex-wrap gap-3">
                    <button
                        type="submit" disabled={busy || technicianId === null}
                        className="min-h-11 rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60"
                    >
                        {busy ? 'Saving…' : title}
                    </button>
                    <button
                        type="button" onClick={() => dialog.current?.close()}
                        className="min-h-11 rounded-md border border-slate-300 bg-white px-4 font-medium hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </dialog>
    );
}

function CandidateCard({ candidate, checked, onChoose }: { candidate: TechnicianRecommendation; checked: boolean; onChoose: () => void }) {
    const { technician } = candidate;
    const id = `candidate-${technician.id}`;

    return (
        <label
            htmlFor={id}
            className={`flex gap-3 rounded-md border p-3 ${checked ? 'border-blue-600 ring-2 ring-blue-600' : 'border-slate-200'} ${candidate.current ? 'opacity-70' : 'cursor-pointer'}`}
        >
            <input id={id} type="radio" name="technician" className="mt-1 size-5 shrink-0" checked={checked} onChange={onChoose} disabled={candidate.current} />
            <span className="block space-y-1">
                <span className="block font-medium">
                    {candidate.rank}. {technician.name}
                    <span className="font-normal text-slate-600"> · {technicianTypeLabel[technician.type]}{technician.company_name && ` (${technician.company_name})`}</span>
                    {candidate.current && <span className="ml-2 rounded bg-slate-200 px-2 py-0.5 text-xs font-medium">Currently assigned</span>}
                </span>
                <span className="block text-sm text-slate-700">{candidate.reasons.join(' · ')}</span>
                {candidate.warnings.map((warning) => (
                    <span key={warning} className="block text-sm font-medium text-amber-900">⚠ {warning}</span>
                ))}
            </span>
        </label>
    );
}

interface TextAreaProps {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint: string;
    maxLength: number;
}

function TextArea({ id, label, value, onChange, error, hint, maxLength }: TextAreaProps) {
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium">{label}</label>
            <textarea
                id={id} rows={2} value={value} onChange={(e) => onChange(e.target.value)} maxLength={maxLength}
                aria-invalid={error ? true : undefined} aria-describedby={error ? `${id}-error` : `${id}-hint`}
                className={`${inputClassName} py-2`}
            />
            {error ? <p id={`${id}-error`} className="text-sm text-red-700">{error}</p> : <p id={`${id}-hint`} className="text-sm text-slate-600">{hint}</p>}
        </div>
    );
}
