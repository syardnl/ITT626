import { formatDateTime, type SlaStatus } from '../lib/api';

const look: Record<SlaStatus['state'], { label: string; className: string }> = {
    on_track: { label: 'On track', className: 'bg-slate-100 text-slate-800' },
    due_soon: { label: 'Due soon', className: 'bg-amber-100 text-amber-900' },
    breached: { label: 'SLA breached', className: 'bg-red-100 text-red-800' },
};

/** The SLA Indicator of an open Complaint: a text label and its colour (never colour alone), with the next deadline. */
export function SlaBadge({ status }: { status: SlaStatus | null | undefined }) {
    if (!status) return null;
    const { label, className } = look[status.state];

    return (
        <span className={`inline-flex flex-wrap items-center gap-1 rounded px-2 py-0.5 text-sm font-medium ${className}`}>
            {status.state === 'breached' && <span aria-hidden="true">⚠</span>}
            {label}
            {status.due_at && <span className="font-normal">· due {formatDateTime(status.due_at)}</span>}
        </span>
    );
}
