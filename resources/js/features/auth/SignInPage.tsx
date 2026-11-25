import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { SubmitButton } from '../../components/SubmitButton';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
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
        <AuthLayout>
            <form onSubmit={onSubmit} className="space-y-4" noValidate>
                <h1 className="text-2xl font-semibold">Sign in</h1>
                <TextField
                    id="email" label="Email" type="email" autoComplete="username" required
                    value={email} onChange={(e) => setEmail(e.target.value)} error={message}
                />
                <TextField
                    id="password" label="Password" type="password" autoComplete="current-password" required
                    value={password} onChange={(e) => setPassword(e.target.value)}
                />
                <SubmitButton busy={submitting} label="Sign in" busyLabel="Signing in…" />
                <p className="text-sm">
                    <Link to="/forgot-password" className="font-medium text-blue-700 underline hover:text-blue-900">
                        Forgot your password?
                    </Link>
                </p>
                <p className="text-sm text-slate-600">
                    Accounts are created by the management office. Visit the counter if you need one.
                </p>
            </form>
        </AuthLayout>
    );
}
