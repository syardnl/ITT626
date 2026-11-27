import { formatDateTime, isStaffRecord, type ComplaintDetail } from '../../lib/api';

/** The repair attempts this viewer may see, oldest first, with their Before and After photos. */
export function MaintenanceRecords({ complaint }: { complaint: ComplaintDetail }) {
    if (complaint.maintenance_records.length === 0) return null;
    const photo = (id: number) => complaint.evidence.find((evidence) => evidence.id === id);

    return (
        <section aria-labelledby="records-heading" className="space-y-3">
            <h2 id="records-heading" className="text-lg font-semibold">Repair work</h2>
            {complaint.maintenance_records.map((record) => (
                <article key={record.id} className="space-y-2 rounded-md border border-slate-200 bg-white p-4">
                    <h3 className="font-medium">
                        Attempt {record.attempt}
                        <span className="font-normal text-slate-600">
                            {' · '}{formatDateTime(record.submitted_at)}{isStaffRecord(record) && ` · ${record.technician.name}`}
                        </span>
                    </h3>
                    <p className="whitespace-pre-line">{record.repair_result}</p>
                    {isStaffRecord(record) && (
                        <dl className="space-y-1 text-sm">
                            {record.parts_used && <div><dt className="inline font-medium">Parts: </dt><dd className="inline">{record.parts_used}</dd></div>}
                            {record.notes && <div><dt className="inline font-medium">Notes: </dt><dd className="inline whitespace-pre-line">{record.notes}</dd></div>}
                        </dl>
                    )}
                    {isStaffRecord(record) && record.expenses.length > 0 && (
                        <table className="w-full text-sm">
                            <caption className="sr-only">Expenses for attempt {record.attempt}</caption>
                            <thead><tr className="text-left text-slate-600"><th className="py-1 font-medium">Item</th><th className="py-1 text-right font-medium">Qty</th><th className="py-1 text-right font-medium">RM</th></tr></thead>
                            <tbody>
                                {record.expenses.map((line, index) => (
                                    <tr key={index} className="border-t border-slate-100"><td className="py-1">{line.description}</td><td className="py-1 text-right">{line.quantity}</td><td className="py-1 text-right">{line.amount}</td></tr>
                                ))}
                            </tbody>
                            <tfoot><tr className="border-t border-slate-300 font-medium"><td className="py-1" colSpan={2}>Total</td><td className="py-1 text-right">{record.expense_total}</td></tr></tfoot>
                        </table>
                    )}
                    {record.photo_ids.length > 0 && (
                        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-5">
                            {record.photo_ids.map((id) => photo(id)).filter((p) => p !== undefined).map((p) => (
                                <li key={p.id}>
                                    <a href={p.url} target="_blank" rel="noreferrer" className="block focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                                        <img src={p.url} alt={`${p.type === 'before_photo' ? 'Before' : 'After'} photo, attempt ${record.attempt}`} loading="lazy" width={p.width} height={p.height} className="aspect-square w-full rounded-md object-cover" />
                                    </a>
                                    <span className="text-xs text-slate-600">{p.type === 'before_photo' ? 'Before' : 'After'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </article>
            ))}
        </section>
    );
}
