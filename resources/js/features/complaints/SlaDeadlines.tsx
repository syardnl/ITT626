import { formatDateTime, type ComplaintDetail, type RepairCycle } from '../../lib/api';

/**
 * SLA deadlines (design §6.3). The office sees every Repair Cycle with its milestones; the reporter
 * and the Technician see the current cycle's deadlines while the Complaint is open.
 */
export function SlaDeadlines({ complaint }: { complaint: ComplaintDetail }) {
    if (complaint.repair_cycles) {
        return (
            <section aria-labelledby="sla-heading">
                <h2 id="sla-heading" className="text-lg font-semibold">Repair Cycles and SLA</h2>
                <ol className="mt-2 space-y-3">
                    {complaint.repair_cycles.map((cycle) => <CycleCard key={cycle.number} cycle={cycle} />)}
                </ol>
            </section>
        );
    }

    if (!complaint.sla || complaint.status === 'resolved' || complaint.status === 'closed') return null;
    const { response_due_at: responseDue, resolution_due_at: resolutionDue } = complaint.sla;

    return (
        <section aria-labelledby="sla-heading" className="rounded-md border border-slate-200 bg-white p-4">
            <h2 id="sla-heading" className="text-lg font-semibold">Target times</h2>
            <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                {responseDue && !complaint.reviewed_at && <Milestone label="Review by" due={responseDue} />}
                {resolutionDue && <Milestone label="Repair approved by" due={resolutionDue} />}
            </dl>
        </section>
    );
}

function CycleCard({ cycle }: { cycle: RepairCycle }) {
    return (
        <li className="rounded-md border border-slate-200 bg-white p-4">
            <p className="font-medium">
                Cycle {cycle.number} <span className="font-normal text-slate-600">· started {formatDateTime(cycle.started_at)}</span>
            </p>
            {cycle.sla_target && (
                <p className="mt-1 text-sm text-slate-600">
                    Target (rule version {cycle.sla_target.version}):
                    {cycle.number === 1 && ` response ${minutesLabel(cycle.sla_target.response_minutes)},`} resolution {minutesLabel(cycle.sla_target.resolution_minutes)}
                </p>
            )}
            <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                {cycle.response_due_at && <Milestone label="Response" due={cycle.response_due_at} done={cycle.responded_at} />}
                {cycle.resolution_due_at && <Milestone label="Resolution" due={cycle.resolution_due_at} done={cycle.resolved_at} />}
            </dl>
            {cycle.breaches.length > 0 && (
                <ul className="mt-2 space-y-1 text-sm">
                    {cycle.breaches.map((breach) => (
                        <li key={breach.type} className="font-medium text-red-800">
                            ⚠ {breach.type === 'response' ? 'Response' : 'Resolution'} target breached (due {formatDateTime(breach.due_at)}, recorded {formatDateTime(breach.detected_at)})
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/** A deadline and, once it happened, when and whether it was in time; spelled out, never colour alone. */
function Milestone({ label, due, done }: { label: string; due: string; done?: string | null }) {
    const late = done != null && new Date(done) > new Date(due);

    return (
        <div>
            <dt className="text-slate-600">{label}</dt>
            <dd>
                due {formatDateTime(due)}
                {done != null && (
                    <span className={late ? 'block font-medium text-red-800' : 'block text-green-800'}>
                        {late ? 'Late' : 'On time'}: {formatDateTime(done)}
                    </span>
                )}
            </dd>
        </div>
    );
}

function minutesLabel(minutes: number): string {
    if (minutes % 1440 === 0) return `${minutes / 1440} ${minutes === 1440 ? 'day' : 'days'}`;
    if (minutes % 60 === 0) return `${minutes / 60} ${minutes === 60 ? 'hour' : 'hours'}`;
    return `${minutes} minutes`;
}
