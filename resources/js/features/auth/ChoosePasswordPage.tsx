import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '../../lib/api';

const copy = {
    reset: { title: 'Choose a new password', button: 'Save new password' },
    setPassword: { title: 'Set your password', button: 'Set password' },
};

/**
 * Read the token from the URL fragment (#token=…), which browsers never send to
 * the server, then drop it from the address bar and history.
 */
function takeTokenFromUrl(): string {
    const token = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '';
    if (window.location.hash) {
        window.history.replaceState(window.history.state, '', window.location.pathname);
    }
    return token;
}

/** Completes both emailed links: "forgot password" resets and new-account set-password links. */
export function ChoosePasswordPage({ purpose }: { purpose: keyof typeof copy }) {
    const [token] = useState(takeTokenFromUrl);
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [done, setDone] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function onSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const res = await api.post<{ message: string }>('/auth/reset-password', {
                token, password, password_confirmation: confirmation,
            });
            setDone(res.data.message);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    const linkProblem = !token ? 'This link is incomplete. Open the link from your email again.' : error?.errors.token?.[0];

    return (
        <AuthLayout>
            <form onSubmit={onSubmit} className="space-y-4" noValidate>
                <h1 className="text-2xl font-semibold">{copy[purpose].title}</h1>
                {done ? (
                    <>
                        <Alert kind="success">{done}</Alert>
                        <Link to="/login" className="inline-flex min-h-11 items-center font-medium text-blue-700 underline hover:text-blue-900">
                            Go to sign in
                        </Link>
                    </>
                ) : (
                    <>
                        {linkProblem && (
                            <Alert kind="error">
                                {linkProblem}{' '}
                                <Link to="/forgot-password" className="font-medium underline">Request a new link</Link>
                            </Alert>
                        )}
                        <TextField
                            id="password" label="New password" type="password" autoComplete="new-password" required
                            minLength={8} value={password} onChange={(e) => setPassword(e.target.value)}
                            error={error?.errors.password?.[0]} hint="Use at least 8 characters."
                        />
                        <TextField
                            id="password_confirmation" label="Confirm new password" type="password" autoComplete="new-password" required
                            value={confirmation} onChange={(e) => setConfirmation(e.target.value)}
                        />
                        <SubmitButton busy={submitting} label={copy[purpose].button} busyLabel="Saving…" />
                    </>
                )}
            </form>
        </AuthLayout>
    );
}
