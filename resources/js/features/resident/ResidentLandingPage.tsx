import { useAuth } from '../../lib/auth';

export function ResidentLandingPage() {
    const { account } = useAuth();

    return (
        <section>
            <h1 className="text-2xl font-semibold md:text-3xl">Welcome, {account?.name}</h1>
            {account?.unit && <p className="mt-2 text-slate-600">Unit {account.unit.code} · {account.unit.name}</p>}
            <p className="mt-4 text-slate-600">The Resident dashboard will appear here in a later release.</p>
        </section>
    );
}
