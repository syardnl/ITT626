import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import { SubmitButton } from '../../components/SubmitButton';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '../../lib/api';

export function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function onSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const res = await api.post<{ message: string }>('/auth/forgot-password', { email });
            setSent(res.data.message);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthLayout>
            <form onSubmit={onSubmit} className="space-y-4" noValidate>
                <h1 className="text-2xl font-semibold">Reset your password</h1>
                {sent ? (
                    <Alert kind="success">{sent} The link works once and expires in 60 minutes.</Alert>
                ) : (
                    <>
                        <p className="text-slate-600">Enter your account email and we will send you a link to choose a new password.</p>
                        {error && !error.errors.email && <Alert kind="error">{error.message}</Alert>}
                        <TextField
                            id="email" label="Email" type="email" autoComplete="username" required
                            value={email} onChange={(e) => setEmail(e.target.value)} error={error?.errors.email?.[0]}
                        />
                        <SubmitButton busy={submitting} label="Send reset link" busyLabel="Sending…" />
                    </>
                )}
                <p className="text-sm">
                    <Link to="/login" className="font-medium text-blue-700 underline hover:text-blue-900">Back to sign in</Link>
                </p>
            </form>
        </AuthLayout>
    );
}
