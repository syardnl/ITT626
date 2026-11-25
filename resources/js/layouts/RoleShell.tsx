import type { ReactNode } from 'react';
import { Navigate, NavLink, Outlet } from 'react-router';
import { roleLabel, type Role } from '../lib/api';
import { landingPath, useAuth } from '../lib/auth';

/** Section links per role; each later ticket adds its screens here. */
const navigation: Record<Role, { to: string; label: string }[]> = {
    resident: [],
    manager: [],
    technician: [],
    administrator: [
        { to: '/admin', label: 'Home' },
        { to: '/admin/users', label: 'Accounts' },
        { to: '/admin/locations', label: 'Locations' },
        { to: '/admin/categories', label: 'Categories' },
        { to: '/admin/sla-targets', label: 'SLA Targets' },
        { to: '/admin/audit-logs', label: 'Audit log' },
    ],
};

/** Guards a section of the SPA for one role and frames it with a header, navigation and sign-out. */
export function RoleShell({ role }: { role: Role }) {
    const { account, loading, signOut } = useAuth();

    if (loading) return <FullPage>Loading…</FullPage>;
    if (!account) return <Navigate to="/login" replace />;
    if (account.role !== role) return <Navigate to={landingPath[account.role]} replace />;

    const links = navigation[role];

    return (
        <div className="min-h-screen">
            <header className="border-b border-slate-200 bg-white">
                <div className="flex items-center justify-between px-4 py-3">
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
                </div>
                {links.length > 0 && (
                    <nav aria-label="Sections" className="flex gap-1 overflow-x-auto px-2">
                        {links.map((link) => (
                            <NavLink
                                key={link.to} to={link.to} end
                                className={({ isActive }) =>
                                    `inline-flex min-h-11 items-center border-b-2 px-3 text-sm font-medium focus:outline-2 focus:outline-blue-600 ${
                                        isActive ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-600 hover:text-slate-900'
                                    }`
                                }
                            >
                                {link.label}
                            </NavLink>
                        ))}
                    </nav>
                )}
            </header>
            <main className="mx-auto max-w-6xl p-4 md:p-8">
                <Outlet />
            </main>
        </div>
    );
}

function FullPage({ children }: { children: ReactNode }) {
    return <div className="flex min-h-screen items-center justify-center text-slate-600">{children}</div>;
}
