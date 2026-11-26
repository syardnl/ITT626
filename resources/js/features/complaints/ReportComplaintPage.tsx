import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { inputClassName, TextField } from '../../components/TextField';
import {
    affectedUnitsLabel, api, ensureCsrfCookie, toApiError, urgencyHint, urgencyLabel,
    type AffectedUnits, type ApiError, type Category, type ComplaintDetail, type LocationNode, type Urgency,
} from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { OpenComplaintsHere } from './OpenComplaintsHere';
import { PhotoPicker } from './PhotoPicker';

interface CommonAreaChoice {
    id: number;
    label: string;
}

/** Every Common Area in the tree, labelled with the path to it ("Block A › Lift 1"). */
function commonAreas(nodes: LocationNode[], path: string[] = []): CommonAreaChoice[] {
    return nodes.flatMap((node) => [
        ...(node.type === 'common_area' ? [{ id: node.id, label: [...path, node.name].join(' › ') }] : []),
        ...commonAreas(node.children, [...path, node.name]),
    ]);
}

export function ReportComplaintPage() {
    const { account } = useAuth();
    const navigate = useNavigate();

    const [place, setPlace] = useState<'my_unit' | 'common_area'>(account?.unit ? 'my_unit' : 'common_area');
    const [areaSearch, setAreaSearch] = useState('');
    const [areas, setAreas] = useState<CommonAreaChoice[]>([]);
    const [locationId, setLocationId] = useState('');
    const [categories, setCategories] = useState<Category[]>([]);

    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [urgency, setUrgency] = useState<Urgency>('low');
    const [safetyRisk, setSafetyRisk] = useState(false);
    const [affectedUnits, setAffectedUnits] = useState<AffectedUnits>('one');
    const [serviceDown, setServiceDown] = useState(false);
    const [photos, setPhotos] = useState<File[]>([]);

    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: Category[] }>('/categories')
            .then((response) => setCategories(response.data.data))
            .catch((caught) => setLoadError(toApiError(caught).message));
    }, []);

    useEffect(() => {
        if (place !== 'common_area') return;
        const timer = setTimeout(() => {
            api.get<{ data: LocationNode[] }>('/locations', { params: areaSearch.trim() ? { q: areaSearch.trim() } : {} })
                .then((response) => {
                    const found = commonAreas(response.data.data);
                    setAreas(found);
                    // Never keep a choice the search no longer shows.
                    setLocationId((chosen) => (found.some((area) => String(area.id) === chosen) ? chosen : ''));
                })
                .catch((caught) => setLoadError(toApiError(caught).message));
        }, 250);
        return () => clearTimeout(timer);
    }, [place, areaSearch]);

    const fieldError = (field: string) => error?.errors[field]?.[0];
    /** The first error about the photos, whether for the set (`photos`) or one of them (`photos.2`). */
    const photoError = () => Object.entries(error?.errors ?? {}).find(([field]) => field === 'photos' || field.startsWith('photos.'))?.[1][0];

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            // Multipart, because of the photos: booleans travel as 1/0 and an empty field as "" (read as null).
            const form = new FormData();
            if (place === 'my_unit') form.append('my_unit', '1');
            else form.append('location_id', locationId);
            form.append('title', title);
            form.append('description', description);
            form.append('category_id', categoryId);
            form.append('urgency', urgency);
            form.append('safety_risk', safetyRisk ? '1' : '0');
            form.append('affected_units', affectedUnits);
            form.append('essential_service_down', serviceDown ? '1' : '0');
            photos.forEach((photo) => form.append('photos[]', photo));

            const response = await api.post<{ data: ComplaintDetail }>('/complaints', form);
            navigate(`/resident/complaints/${response.data.data.id}`, { state: { justReported: response.data.data.reference_no } });
        } catch (caught) {
            setError(toApiError(caught));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="mx-auto max-w-xl">
            <h1 className="text-2xl font-semibold">Report a problem</h1>
            <p className="mt-1 text-slate-600">Tell the management office what is wrong and where.</p>

            <form onSubmit={submit} noValidate className="mt-6 space-y-6">
                {loadError && <Alert kind="error">{loadError}</Alert>}
                {error && <Alert kind="error">{Object.keys(error.errors).length ? 'Please check the answers marked below.' : error.message}</Alert>}

                <fieldset className="space-y-2" aria-describedby={fieldError('my_unit') ? 'my-unit-error' : undefined}>
                    <legend className="text-sm font-medium">Where is the problem?</legend>
                    {account?.unit && (
                        <label className="flex min-h-11 items-center gap-3">
                            <input type="radio" name="place" checked={place === 'my_unit'} onChange={() => setPlace('my_unit')} />
                            My unit ({account.unit.code})
                        </label>
                    )}
                    <label className="flex min-h-11 items-center gap-3">
                        <input type="radio" name="place" checked={place === 'common_area'} onChange={() => setPlace('common_area')} />
                        A Common Area (lift, corridor, pool…)
                    </label>
                    {fieldError('my_unit') && <p id="my-unit-error" className="text-sm text-red-700">{fieldError('my_unit')}</p>}
                </fieldset>

                {place === 'common_area' && (
                    <div className="space-y-3">
                        <TextField
                            id="area-search" label="Search Common Areas" type="search" value={areaSearch}
                            onChange={(e) => setAreaSearch(e.target.value)} hint="For example: lift, pool, B-02"
                        />
                        <div className="space-y-1">
                            <label htmlFor="location" className="block text-sm font-medium">Common Area</label>
                            <select
                                id="location" value={locationId} onChange={(e) => setLocationId(e.target.value)}
                                aria-invalid={fieldError('location_id') ? true : undefined}
                                aria-describedby={fieldError('location_id') ? 'location-error' : undefined}
                                className={inputClassName}
                            >
                                <option value="">Choose a Common Area</option>
                                {areas.map((area) => <option key={area.id} value={area.id}>{area.label}</option>)}
                            </select>
                            {fieldError('location_id') && <p id="location-error" className="text-sm text-red-700">{fieldError('location_id')}</p>}
                        </div>
                        {locationId && <OpenComplaintsHere locationId={Number(locationId)} />}
                    </div>
                )}

                <TextField
                    id="title" label="Short title" value={title} onChange={(e) => setTitle(e.target.value)}
                    maxLength={150} hint="5–150 characters, e.g. “Lift B1 not operating”" error={fieldError('title')}
                />

                <div className="space-y-1">
                    <label htmlFor="description" className="block text-sm font-medium">What is wrong?</label>
                    <textarea
                        id="description" rows={5} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={3000}
                        aria-invalid={fieldError('description') ? true : undefined}
                        aria-describedby={fieldError('description') ? 'description-error description-hint' : 'description-hint'}
                        className={`${inputClassName} py-2`}
                    />
                    {fieldError('description') && <p id="description-error" className="text-sm text-red-700">{fieldError('description')}</p>}
                    <p id="description-hint" className="text-sm text-slate-600">10–3000 characters. When did it start? What have you noticed?</p>
                </div>

                <div className="space-y-1">
                    <label htmlFor="category" className="block text-sm font-medium">Category (optional)</label>
                    <select
                        id="category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className={inputClassName}
                        aria-invalid={fieldError('category_id') ? true : undefined}
                        aria-describedby={fieldError('category_id') ? 'category-error' : undefined}
                    >
                        <option value="">Not sure — let the office decide</option>
                        {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                    {fieldError('category_id') && <p id="category-error" className="text-sm text-red-700">{fieldError('category_id')}</p>}
                </div>

                <fieldset className="space-y-2" aria-describedby={fieldError('urgency') ? 'urgency-error' : undefined}>
                    <legend className="text-sm font-medium">How urgent is it?</legend>
                    {(Object.keys(urgencyLabel) as Urgency[]).map((value) => (
                        <label key={value} className="flex min-h-11 items-start gap-3 py-1">
                            <input type="radio" name="urgency" className="mt-1" checked={urgency === value} onChange={() => setUrgency(value)} />
                            <span><span className="font-medium">{urgencyLabel[value]}</span><span className="block text-sm text-slate-600">{urgencyHint[value]}</span></span>
                        </label>
                    ))}
                    {fieldError('urgency') && <p id="urgency-error" className="text-sm text-red-700">{fieldError('urgency')}</p>}
                </fieldset>

                <fieldset className="space-y-2" aria-describedby={fieldError('affected_units') ? 'affected-error' : undefined}>
                    <legend className="text-sm font-medium">How many Units are affected?</legend>
                    {(Object.keys(affectedUnitsLabel) as AffectedUnits[]).map((value) => (
                        <label key={value} className="flex min-h-11 items-center gap-3">
                            <input type="radio" name="affected" checked={affectedUnits === value} onChange={() => setAffectedUnits(value)} />
                            {affectedUnitsLabel[value]}
                        </label>
                    ))}
                    {fieldError('affected_units') && <p id="affected-error" className="text-sm text-red-700">{fieldError('affected_units')}</p>}
                </fieldset>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">Impact</legend>
                    <label className="flex min-h-11 items-center gap-3">
                        <input type="checkbox" checked={safetyRisk} onChange={(e) => setSafetyRisk(e.target.checked)} />
                        Someone could get hurt (safety risk)
                    </label>
                    <label className="flex min-h-11 items-center gap-3">
                        <input type="checkbox" checked={serviceDown} onChange={(e) => setServiceDown(e.target.checked)} />
                        Water, electricity, a lift or security access is not working
                    </label>
                    {['safety_risk', 'essential_service_down'].map((field) => fieldError(field) && (
                        <p key={field} className="text-sm text-red-700">{fieldError(field)}</p>
                    ))}
                </fieldset>

                <PhotoPicker photos={photos} onChange={setPhotos} error={photoError()} />

                <SubmitButton busy={busy} label="Submit Complaint" busyLabel="Submitting…" />
            </form>
        </section>
    );
}
