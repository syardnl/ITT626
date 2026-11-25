import type { ReactNode } from 'react';
import { Navigate, Outlet } from 'react-router';
import type { Role } from '../lib/api';
import { landingPath, useAuth } from '../lib/auth';

const roleLabel: Record<Role, string> = {
    resident: 'Resident',
    manager: 'Manager',
    technician: 'Technician',
    administrator: 'Administrator',
};

/** Guards a section of the SPA for one role and frames it with a header and sign-out. */
export function RoleShell({ role }: { role: Role }) {
    const { account, loading, signOut } = useAuth();

    if (loading) return <FullPage>Loading…</FullPage>;
    if (!account) return <Navigate to="/login" replace />;
    if (account.role !== role) return <Navigate to={landingPath[account.role]} replace />;

    return (
        <div className="min-h-screen">
            <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
                <div>
                    <p className="font-semibold">Apartment Maintenance</p>
                    <p className="text-sm text-slate-600">
                        {account.name} · {roleLabel[account.role]}
                    </p>
                </div>
                <button
                    type="button" onClick={() => void signOut()}
                    className="min-h-11 rounded-md border border-slate-300 px-4 text-sm font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600"
                >
                    Sign out
                </button>
            </header>
            <main className="mx-auto max-w-5xl p-4 md:p-8">
                <Outlet />
            </main>
        </div>
    );
}

function FullPage({ children }: { children: ReactNode }) {
    return <div className="flex min-h-screen items-center justify-center text-slate-600">{children}</div>;
}
