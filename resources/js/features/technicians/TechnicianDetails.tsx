import { availabilityLabel, technicianTypeLabel, type Technician } from '../../lib/api';

const availabilityClass: Record<Technician['availability'], string> = {
    available: 'bg-green-100 text-green-900',
    busy: 'bg-amber-100 text-amber-900',
    on_leave: 'bg-slate-300 text-slate-900',
};

export function Badge({ className, children }: { className: string; children: string }) {
    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${className}`}>{children}</span>;
}

export function AvailabilityBadge({ availability }: { availability: Technician['availability'] }) {
    return <Badge className={availabilityClass[availability]}>{availabilityLabel[availability]}</Badge>;
}

/** Read-only summary of a profile: what the office has recorded. */
export function TechnicianDetails({ technician }: { technician: Technician }) {
    const rows: [string, string][] = [
        ['Type', technicianTypeLabel[technician.type] + (technician.company_name ? ` · ${technician.company_name}` : '')],
        ['Skills', technician.skills.map((s) => s.name + (s.active ? '' : ' (inactive)')).join(', ')],
        ['Home Block', `${technician.home_block.code} · ${technician.home_block.name}`],
        ['Maximum tasks', String(technician.max_tasks)],
    ];

    return (
        <dl className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <div className="flex items-center justify-between gap-4 px-4 py-3">
                <dt className="text-sm font-medium text-slate-600">Availability</dt>
                <dd><AvailabilityBadge availability={technician.availability} /></dd>
            </div>
            {rows.map(([label, value]) => (
                <div key={label} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:justify-between sm:gap-4">
                    <dt className="text-sm font-medium text-slate-600">{label}</dt>
                    <dd className="sm:text-right">{value}</dd>
                </div>
            ))}
        </dl>
    );
}
