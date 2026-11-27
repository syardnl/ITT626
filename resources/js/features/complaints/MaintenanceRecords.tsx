import { formatDateTime, isStaffRecord, photoTypeLabel, type ComplaintDetail, type EvidencePhoto, type MaintenanceRecord, type MaintenanceRecordSummary } from '../../lib/api';

/** The Maintenance Records this viewer may see, oldest first, with their Before and After photos. */
export function MaintenanceRecords({ complaint }: { complaint: ComplaintDetail }) {
    if (complaint.maintenance_records.length === 0) return null;

    return (
        <section aria-labelledby="records-heading" className="space-y-3">
            <h2 id="records-heading" className="text-lg font-semibold">Maintenance Records</h2>
            {complaint.maintenance_records.map((record) => (
                <article key={record.id} className="space-y-2 rounded-md border border-slate-200 bg-white p-4">
                    <h3 className="font-medium">
                        Maintenance Record {record.attempt}
                        <span className="font-normal text-slate-600">
                            {' · '}{formatDateTime(record.submitted_at)}{isStaffRecord(record) && ` · ${record.technician.name}`}
                        </span>
                    </h3>
                    <RecordDetails record={record} />
                    {isStaffRecord(record) && <Verification record={record} />}
                    <PhotoGrid photos={recordPhotos(complaint, record)} attempt={record.attempt} />
                </article>
            ))}
        </section>
    );
}

/** What was repaired, and for staff the parts, notes and expense lines with their total. */
export function RecordDetails({ record }: { record: MaintenanceRecord | MaintenanceRecordSummary }) {
    return (
        <>
            <p className="whitespace-pre-line">{record.repair_result}</p>
            {isStaffRecord(record) && (
                <dl className="space-y-1 text-sm">
                    {record.parts_used && <div><dt className="inline font-medium">Parts: </dt><dd className="inline">{record.parts_used}</dd></div>}
                    {record.notes && <div><dt className="inline font-medium">Notes: </dt><dd className="inline whitespace-pre-line">{record.notes}</dd></div>}
                </dl>
            )}
            {isStaffRecord(record) && record.expenses.length > 0 && (
                <table className="w-full text-sm">
                    <caption className="sr-only">Expenses for Maintenance Record {record.attempt}</caption>
                    <thead><tr className="text-left text-slate-600"><th className="py-1 font-medium">Item</th><th className="py-1 text-right font-medium">Qty</th><th className="py-1 text-right font-medium">RM</th></tr></thead>
                    <tbody>
                        {record.expenses.map((line, index) => (
                            <tr key={index} className="border-t border-slate-100"><td className="py-1">{line.description}</td><td className="py-1 text-right">{line.quantity}</td><td className="py-1 text-right">{line.amount}</td></tr>
                        ))}
                    </tbody>
                    <tfoot><tr className="border-t border-slate-300 font-medium"><td className="py-1" colSpan={2}>Total</td><td className="py-1 text-right">{record.expense_total}</td></tr></tfoot>
                </table>
            )}
        </>
    );
}

/** The photos of one record, in the order they were sent. */
export function recordPhotos(complaint: ComplaintDetail, record: MaintenanceRecord | MaintenanceRecordSummary): EvidencePhoto[] {
    return record.photo_ids.map((id) => complaint.evidence.find((evidence) => evidence.id === id)).filter((photo) => photo !== undefined);
}

export function PhotoGrid({ photos, attempt }: { photos: EvidencePhoto[]; attempt: number }) {
    if (photos.length === 0) return null;

    return (
        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-5">
            {photos.map((photo) => (
                <li key={photo.id}>
                    <a href={photo.url} target="_blank" rel="noreferrer" className="block focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                        <img src={photo.url} alt={`${photoTypeLabel[photo.type]} photo, Maintenance Record ${attempt}`} loading="lazy" width={photo.width} height={photo.height} className="aspect-square w-full rounded-md object-cover" />
                    </a>
                    <span className="text-xs text-slate-600">{photoTypeLabel[photo.type]}</span>
                </li>
            ))}
        </ul>
    );
}

function Verification({ record }: { record: MaintenanceRecord }) {
    if (!record.verification_outcome || !record.verified_by || !record.verified_at) return null;

    return record.verification_outcome === 'approved'
        ? <p className="text-sm text-green-800">Approved by {record.verified_by.name} · {formatDateTime(record.verified_at)}</p>
        : (
            <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm">
                <p className="font-medium text-amber-900">Returned by {record.verified_by.name} · {formatDateTime(record.verified_at)}</p>
                {record.verification_reason && <p className="mt-1 whitespace-pre-line">{record.verification_reason}</p>}
            </div>
        );
}
