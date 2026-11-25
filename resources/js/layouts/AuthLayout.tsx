import type { ReactNode } from 'react';

/** Split layout for signed-out screens: brand panel on desktop, compact form on mobile. */
export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <main className="grid min-h-screen md:grid-cols-2">
            <section className="hidden flex-col justify-center bg-slate-800 p-12 text-slate-100 md:flex">
                <p className="text-3xl font-semibold">Apartment Maintenance</p>
                <p className="mt-4 max-w-md text-slate-300">
                    Report problems, follow repairs and see how the management office is keeping up.
                </p>
            </section>
            <section className="flex items-center justify-center p-6">
                <div className="w-full max-w-sm">{children}</div>
            </section>
        </main>
    );
}
