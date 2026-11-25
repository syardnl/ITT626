import { useEffect, useState, type FormEvent } from 'react';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName, TextField } from '../../components/TextField';
import {
    api, ensureCsrfCookie, priorities, priorityLabel, toApiError,
    type ApiError, type Category, type Priority,
} from '../../lib/api';

/** The ten fixed Categories: rename, change default priority, deactivate or reactivate. */
export function CategoriesPage() {
    const [categories, setCategories] = useState<Category[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: Category[] }>('/admin/categories')
            .then((res) => setCategories(res.data.data))
            .catch((e) => setLoadError(toApiError(e).message));
    }, []);

    function onSaved(saved: Category) {
        setCategories((list) => list?.map((c) => (c.id === saved.id ? saved : c)) ?? null);
    }

    return (
        <section className="space-y-6">
            <div>
                <h1 className="text-2xl font-semibold md:text-3xl">Categories</h1>
                <p className="mt-2 text-slate-600">
                    The ten kinds of maintenance problem. Each has one matching Skill that is renamed and deactivated with it.
                    The default priority is shown to Managers as a baseline only.
                </p>
            </div>
            {loadError && <Alert kind="error">{loadError}</Alert>}
            {!categories && !loadError && <p role="status" className="text-slate-600">Loading Categories…</p>}
            {categories && (
                <ul className="grid gap-4 md:grid-cols-2" aria-label="Categories">
                    {categories.map((category) => (
                        <li key={category.id}><CategoryCard category={category} onSaved={onSaved} /></li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function CategoryCard({ category, onSaved }: { category: Category; onSaved: (category: Category) => void }) {
    const [name, setName] = useState(category.name);
    const [priority, setPriority] = useState<Priority>(category.default_priority);
    const [error, setError] = useState<ApiError | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const id = `category-${category.id}`;

    async function save(changes: Partial<Category>, done: string) {
        setBusy(true);
        setError(null);
        setNotice(null);
        try {
            await ensureCsrfCookie();
            const res = await api.patch<{ data: Category }>(`/admin/categories/${category.id}`, changes);
            onSaved(res.data.data);
            setNotice(done);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        void save({ name, default_priority: priority }, 'Changes saved.');
    }

    function toggleActive() {
        const verb = category.active ? 'Deactivate' : 'Reactivate';
        const consequence = category.active ? ' It will no longer be offered when classifying Complaints.' : '';
        if (!window.confirm(`${verb} ${category.name} and its Skill?${consequence}`)) return;
        void save({ active: !category.active }, `${category.name} ${category.active ? 'deactivated' : 'reactivated'}.`);
    }

    const priorityError = error?.errors.default_priority?.[0];

    return (
        <form onSubmit={onSubmit} aria-labelledby={`${id}-heading`} className={`space-y-4 rounded-lg border bg-white p-4 ${category.active ? 'border-slate-200' : 'border-dashed border-slate-300'}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id={`${id}-heading`} className={`text-lg font-semibold ${category.active ? '' : 'text-slate-500'}`}>{category.name}</h2>
                {!category.active && <span className="rounded bg-slate-300 px-1.5 py-0.5 text-xs font-medium text-slate-900">Inactive</span>}
            </div>
            {category.skill && <p className="text-sm text-slate-600">Skill: {category.skill.name}</p>}
            {notice && <Alert kind="success">{notice}</Alert>}
            {error && Object.keys(error.errors).length === 0 && <Alert kind="error">{error.message}</Alert>}
            <TextField id={`${id}-name`} label="Name" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} error={error?.errors.name?.[0]} />
            <div className="space-y-1">
                <label htmlFor={`${id}-priority`} className="block text-sm font-medium">Default priority</label>
                <select
                    id={`${id}-priority`} value={priority} onChange={(e) => setPriority(e.target.value as Priority)} className={inputClassName}
                    aria-invalid={priorityError ? true : undefined} aria-describedby={priorityError ? `${id}-priority-error` : undefined}
                >
                    {priorities.map((p) => <option key={p} value={p}>{priorityLabel[p]}</option>)}
                </select>
                {priorityError && <p id={`${id}-priority-error`} role="alert" className="text-sm text-red-700">{priorityError}</p>}
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <SubmitButton busy={busy} label="Save changes" busyLabel="Saving…" />
                <button
                    type="button" onClick={toggleActive} disabled={busy}
                    className={`min-h-11 rounded-md border font-medium focus:outline-2 focus:outline-blue-600 disabled:opacity-60 ${category.active ? 'border-red-300 text-red-800 hover:bg-red-50' : 'border-green-300 text-green-800 hover:bg-green-50'}`}
                >
                    {category.active ? 'Deactivate' : 'Reactivate'}
                </button>
            </div>
        </form>
    );
}
