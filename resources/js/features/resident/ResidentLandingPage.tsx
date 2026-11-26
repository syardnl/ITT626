import { Link } from 'react-router';
import { useAuth } from '../../lib/auth';

export function ResidentLandingPage() {
    const { account } = useAuth();

    return (
        <section>
            <h1 className="text-2xl font-semibold md:text-3xl">Welcome, {account?.name}</h1>
            {account?.unit && <p className="mt-2 text-slate-600">Unit {account.unit.code} · {account.unit.name}</p>}
            <Link
                to="/resident/complaints/new"
                className="mt-6 inline-flex min-h-11 items-center rounded-md bg-blue-700 px-5 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600"
            >
                Report a problem
            </Link>
        </section>
    );
}
