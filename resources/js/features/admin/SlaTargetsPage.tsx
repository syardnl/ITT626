import { useEffect, useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { TextField } from '../../components/TextField';
import {
    api, ensureCsrfCookie, formatDateTime, formatMinutes, priorityLabel, toApiError,
    type ApiError, type SlaRule,
} from '../../lib/api';

/** The four SLA Targets, one per Priority; each saved change raises the rule version. */
export function SlaTargetsPage() {
    const [rules, setRules] = useState<SlaRule[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: SlaRule[] }>('/admin/sla-rules')
            .then((res) => setRules(res.data.data))
            .catch((e) => setLoadError(toApiError(e).message));
    }, []);

    function onSaved(saved: SlaRule) {
        setRules((list) => list?.map((r) => (r.id === saved.id ? saved : r)) ?? null);
    }

    return (
        <section className="space-y-6">
            <div>
                <h1 className="text-2xl font-semibold md:text-3xl">SLA Targets</h1>
                <p className="mt-2 text-slate-600">
                    The time allowed for Response and for Resolution at each Priority, counted in calendar minutes
                    (nights, weekends and holidays included). A change applies to Repair Cycles that start after it.
                </p>
            </div>
            {loadError && <Alert kind="error">{loadError}</Alert>}
            {!rules && !loadError && <p role="status" className="text-slate-600">Loading SLA Targets…</p>}
            {rules && (
                <ul className="grid gap-4 md:grid-cols-2" aria-label="SLA Targets">
                    {rules.map((rule) => <li key={rule.id}><SlaRuleCard rule={rule} onSaved={onSaved} /></li>)}
                </ul>
            )}
        </section>
    );
}

function SlaRuleCard({ rule, onSaved }: { rule: SlaRule; onSaved: (rule: SlaRule) => void }) {
    const [response, setResponse] = useState(String(rule.response_minutes));
    const [resolution, setResolution] = useState(String(rule.resolution_minutes));
    const [error, setError] = useState<ApiError | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const id = `sla-${rule.priority}`;

    async function onSubmit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setNotice(null);
        try {
            await ensureCsrfCookie();
            const res = await api.patch<{ data: SlaRule }>(`/admin/sla-rules/${rule.id}`, {
                response_minutes: Number(response),
                resolution_minutes: Number(resolution),
            });
            const saved = res.data.data;
            onSaved(saved);
            setNotice(saved.version === rule.version ? 'Nothing changed.' : `Saved as rule version ${saved.version}.`);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    /** Read the typed minutes back in hours and days, so 1440 is plainly "1 day". */
    const hint = (value: string) => {
        const minutes = Number(value);
        return Number.isInteger(minutes) && minutes > 0 ? `Whole minutes · ${formatMinutes(minutes)}` : 'Whole minutes, at least 1.';
    };

    return (
        <form onSubmit={onSubmit} aria-labelledby={`${id}-heading`} className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id={`${id}-heading`} className="text-lg font-semibold">{priorityLabel[rule.priority]}</h2>
                <p className="text-sm text-slate-600">
                    Version {rule.version}{rule.updated_at && ` · changed ${formatDateTime(rule.updated_at)}`}
                </p>
            </div>
            {notice && <Alert kind="success">{notice}</Alert>}
            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}
            <TextField
                id={`${id}-response`} label="Response target (minutes)" type="number" inputMode="numeric" min={1} step={1} required
                value={response} onChange={(e) => setResponse(e.target.value)} error={error?.errors.response_minutes?.[0]} hint={hint(response)}
            />
            <TextField
                id={`${id}-resolution`} label="Resolution target (minutes)" type="number" inputMode="numeric" min={1} step={1} required
                value={resolution} onChange={(e) => setResolution(e.target.value)} error={error?.errors.resolution_minutes?.[0]} hint={hint(resolution)}
            />
            <SubmitButton busy={busy} label="Save targets" busyLabel="Saving…" />
        </form>
    );
}
