import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import {
    api, categoryName, complaintStatusLabel, formatDateTime, priorityLabel, toApiError,
    type Complaint, type ComplaintStatus, type ComplaintSummary, type LocationNode, type Paginated,
} from '../../lib/api';
import { useAuth } from '../../lib/auth';

interface MyCounts {
    open: number;
    resolved: number;
}

interface BlockComplaint extends ComplaintSummary {
    place: string;
}

const LATEST = 5;

/** The Block holding this Unit, from the Location tree. */
function blockOf(tree: LocationNode[], unitId: number): LocationNode | null {
    const contains = (node: LocationNode): boolean => node.id === unitId || node.children.some(contains);
    return tree.find((node) => node.type === 'block' && contains(node)) ?? null;
}

/** The Block's Common Areas at Block or Floor level, each with a readable place name. */
function commonAreasIn(block: LocationNode): { id: number; place: string }[] {
    return [
        ...block.children.filter((node) => node.type === 'common_area').map((area) => ({ id: area.id, place: area.name })),
        ...block.children.filter((node) => node.type === 'floor').flatMap((floor) => floor.children
            .filter((node) => node.type === 'common_area').map((area) => ({ id: area.id, place: area.name }))),
    ];
}

/**
 * Open Complaints raised at Common Areas in the Resident's Block, newest first. There is no
 * /dashboard endpoint yet (Phase 4), so this asks each Common Area's list of open Complaints.
 */
async function openComplaintsInBlock(unitId: number): Promise<{ block: LocationNode | null; complaints: BlockComplaint[] }> {
    const tree = (await api.get<{ data: LocationNode[] }>('/locations')).data.data;
    const block = blockOf(tree, unitId);
    if (!block) return { block: null, complaints: [] };

    const lists = await Promise.all(commonAreasIn(block).map(async (area) => {
        const response = await api.get<Paginated<ComplaintSummary>>(`/locations/${area.id}/open-complaints`);
        return response.data.data.map((complaint) => ({ ...complaint, place: area.place }));
    }));
    const complaints = lists.flat().sort((a, b) => b.created_at.localeCompare(a.created_at));

    return { block, complaints };
}

/** Open is everything not Resolved or Closed; Resolved counts Closed too, as the problem is fixed either way. */
async function myCounts(total: number): Promise<MyCounts> {
    const count = async (status: ComplaintStatus) =>
        (await api.get<Paginated<Complaint>>('/complaints', { params: { scope: 'mine', status, per_page: 1 } })).data.meta.total;
    const [resolved, closed] = await Promise.all([count('resolved'), count('closed')]);
    return { open: total - resolved - closed, resolved: resolved + closed };
}

export function ResidentLandingPage() {
    const { account } = useAuth();
    const unitId = account?.unit?.id;

    const [latest, setLatest] = useState<Complaint[] | null>(null);
    const [counts, setCounts] = useState<MyCounts | null>(null);
    const [mineError, setMineError] = useState<string | null>(null);

    const [block, setBlock] = useState<LocationNode | null>(null);
    const [blockComplaints, setBlockComplaints] = useState<BlockComplaint[] | null>(null);
    const [blockError, setBlockError] = useState<string | null>(null);

    useEffect(() => {
        let current = true;
        api.get<Paginated<Complaint>>('/complaints', { params: { scope: 'mine', per_page: LATEST } })
            .then(async (response) => {
                if (!current) return;
                setLatest(response.data.data);
                const found = await myCounts(response.data.meta.total);
                if (current) setCounts(found);
            })
            .catch((caught) => current && setMineError(toApiError(caught).message));
        return () => { current = false; };
    }, []);

    useEffect(() => {
        if (!unitId) return;
        let current = true;
        openComplaintsInBlock(unitId)
            .then((found) => {
                if (!current) return;
                setBlock(found.block);
                setBlockComplaints(found.complaints);
            })
            .catch((caught) => current && setBlockError(toApiError(caught).message));
        return () => { current = false; };
    }, [unitId]);

    return (
        <div className="space-y-8">
            <section>
                <h1 className="text-2xl font-semibold md:text-3xl">Welcome, {account?.name}</h1>
                {account?.unit && (
                    <p className="mt-1 text-slate-600">
                        Unit {account.unit.code}{block && ` · ${block.name}`}
                    </p>
                )}
                <Link
                    to="/resident/complaints/new"
                    className="mt-5 flex min-h-14 w-full items-center justify-center rounded-lg bg-blue-700 px-6 text-lg font-semibold text-white shadow-sm hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 sm:inline-flex sm:w-auto"
                >
                    Report a problem
                </Link>
            </section>

            {mineError && <Alert kind="error">{mineError}</Alert>}

            <section aria-labelledby="counts-heading">
                <h2 id="counts-heading" className="sr-only">My Complaints at a glance</h2>
                <dl className="grid grid-cols-2 gap-3 sm:max-w-md">
                    <CountCard label="Open" value={counts?.open} />
                    <CountCard label="Resolved" value={counts?.resolved} />
                </dl>
            </section>

            <section aria-labelledby="latest-heading">
                <div className="flex items-baseline justify-between gap-3">
                    <h2 id="latest-heading" className="text-lg font-semibold">My latest Complaints</h2>
                    <Link to="/resident/complaints" className="text-sm font-medium text-blue-700 underline-offset-2 hover:underline">See all</Link>
                </div>
                {!latest && !mineError && <p className="mt-3 text-sm text-slate-600">Loading…</p>}
                {latest?.length === 0 && <p className="mt-3 text-slate-600">You have not reported any problems yet.</p>}
                {latest && latest.length > 0 && (
                    <ul className="mt-3 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                        {latest.map((complaint) => (
                            <li key={complaint.id}>
                                <Link to={`/resident/complaints/${complaint.id}`} className="block p-4 hover:bg-slate-50 focus:outline-2 focus:outline-blue-600">
                                    <p className="font-medium">{complaint.title}</p>
                                    <p className="mt-1 text-sm text-slate-600">
                                        {complaint.reference_no} · {complaint.location.name} · {formatDateTime(complaint.created_at)}
                                    </p>
                                    <p className="mt-1 text-sm">
                                        <span className="font-medium">{complaintStatusLabel[complaint.status]}</span>
                                        {` · ${priorityLabel[complaint.priority]} priority`}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {unitId && (
                <section aria-labelledby="block-heading">
                    <h2 id="block-heading" className="text-lg font-semibold">Open problems in {block?.name ?? 'my Block'}</h2>
                    <p className="mt-1 text-sm text-slate-600">Reported at your Block's lifts, corridors and other Common Areas. Problems inside Units are never shown.</p>
                    {blockError && <div className="mt-3"><Alert kind="error">{blockError}</Alert></div>}
                    {!blockComplaints && !blockError && <p className="mt-3 text-sm text-slate-600">Loading…</p>}
                    {blockComplaints?.length === 0 && <p className="mt-3 text-slate-600">No open problems in your Block right now.</p>}
                    {blockComplaints && blockComplaints.length > 0 && (
                        <ul className="mt-3 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                            {blockComplaints.map((complaint) => (
                                <li key={complaint.id} className="p-4">
                                    <p className="font-medium">{complaint.title}</p>
                                    <p className="mt-1 text-sm text-slate-600">
                                        {complaint.place} · {categoryName(complaint.category)} · {formatDateTime(complaint.created_at)}
                                    </p>
                                    <p className="mt-1 text-sm">
                                        <span className="font-medium">{complaintStatusLabel[complaint.status]}</span>
                                        {complaint.mine && ' · You reported this'}
                                        {!complaint.mine && complaint.following && ' · You are following this'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </div>
    );
}

function CountCard({ label, value }: { label: string; value: number | undefined }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
            <dt className="text-sm text-slate-600">{label}</dt>
            <dd className="mt-1 text-3xl font-semibold">{value ?? '–'}</dd>
        </div>
    );
}
