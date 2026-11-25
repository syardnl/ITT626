import { useAuth } from '../../lib/auth';

export function AdministratorLandingPage() {
    const { account } = useAuth();

    return (
        <section>
            <h1 className="text-2xl font-semibold md:text-3xl">Welcome, {account?.name}</h1>

            <p className="mt-4 text-slate-600">The Administrator dashboard will appear here in a later release.</p>
        </section>
    );
}
