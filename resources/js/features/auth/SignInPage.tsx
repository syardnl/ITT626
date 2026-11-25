import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router';
import { toApiError, type ApiError } from '../../lib/api';
import { landingPath, useAuth } from '../../lib/auth';

export function SignInPage() {
    const { account, signIn } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (account) return <Navigate to={landingPath[account.role]} replace />;

    async function onSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            const signedIn = await signIn(email, password);
            navigate(landingPath[signedIn.role], { replace: true });
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    const message =
        error?.status === 429
            ? 'Too many sign-in attempts. Please wait a minute and try again.'
            : (error?.errors.email?.[0] ?? error?.message);

    return (
        <main className="grid min-h-screen md:grid-cols-2">
            <section className="hidden flex-col justify-center bg-slate-800 p-12 text-slate-100 md:flex">
                <p className="text-3xl font-semibold">Apartment Maintenance</p>
                <p className="mt-4 max-w-md text-slate-300">
                    Report problems, follow repairs and see how the management office is keeping up.
                </p>
            </section>
            <section className="flex items-center justify-center p-6">
                <form onSubmit={onSubmit} className="w-full max-w-sm space-y-4" noValidate>
                    <h1 className="text-2xl font-semibold">Sign in</h1>
                    {message && (
                        <p id="sign-in-error" role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                            {message}
                        </p>
                    )}
                    <div className="space-y-1">
                        <label htmlFor="email" className="block text-sm font-medium">Email</label>
                        <input
                            id="email" type="email" autoComplete="username" required value={email}
                            aria-invalid={message ? true : undefined}
                            aria-describedby={message ? 'sign-in-error' : undefined}
                            onChange={(e) => setEmail(e.target.value)}
                            className="block min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 focus:outline-2 focus:outline-blue-600"
                        />
                    </div>
                    <div className="space-y-1">
                        <label htmlFor="password" className="block text-sm font-medium">Password</label>
                        <input
                            id="password" type="password" autoComplete="current-password" required value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="block min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 focus:outline-2 focus:outline-blue-600"
                        />
                    </div>
                    <button
                        type="submit" disabled={submitting}
                        className="min-h-11 w-full rounded-md bg-blue-700 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60"
                    >
                        {submitting ? 'Signing in…' : 'Sign in'}
                    </button>
                    <p className="text-sm text-slate-600">
                        Accounts are created by the management office. Visit the counter if you need one.
                    </p>
                </form>
            </section>
        </main>
    );
}
