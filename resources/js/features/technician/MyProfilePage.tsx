import { useEffect, useState } from 'react';
import { Alert } from '../../components/Alert';
import { api, toApiError, type ApiError, type Technician } from '../../lib/api';
import { TechnicianDetails } from '../technicians/TechnicianDetails';

/** The signed-in Technician's own profile, as the office recorded it. Read-only. */
export function MyProfilePage() {
    const [technician, setTechnician] = useState<Technician | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        api.get<{ data: Technician }>('/technician/profile')
            .then((res) => setTechnician(res.data.data))
            .catch((e) => setError(toApiError(e)));
    }, []);

    return (
        <section className="space-y-4">
            <h1 className="text-2xl font-semibold md:text-3xl">My profile</h1>
            {!technician && !error && <p role="status" className="text-slate-600">Loading your profile…</p>}
            {error?.status === 404 && (
                <p className="rounded-lg border border-dashed border-slate-300 p-6 text-slate-600">
                    The office has not set up your Technician profile yet. Ask a Manager to add your Skills and home Block.
                </p>
            )}
            {error && error.status !== 404 && <Alert kind="error">{error.message}</Alert>}
            {technician && (
                <>
                    <p className="text-slate-600">This is what the office has recorded about you. Ask a Manager if anything needs to change.</p>
                    <TechnicianDetails technician={technician} />
                </>
            )}
        </section>
    );
}
