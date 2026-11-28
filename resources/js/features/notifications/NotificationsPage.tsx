import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { Alert } from '../../components/Alert';
import { formatDateTime, toApiError } from '../../lib/api';
import { fetchNotifications, markAllRead, markRead, type AppNotification, type NotificationPage } from '../../lib/notifications';

/** The signed-in account's notifications, newest first; opening one marks it read. */
export function NotificationsPage() {
    const navigate = useNavigate();
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<NotificationPage | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        fetchNotifications(page).then(setResult).catch((caught) => setError(toApiError(caught).message));
    }, [page]);
    useEffect(load, [load]);

    async function open(notification: AppNotification) {
        try {
            if (!notification.read_at) await markRead(notification.id);
        } catch {
            // Opening the Complaint matters more than the read mark; it stays unread.
        }
        navigate(notification.link);
    }

    async function readAll() {
        setBusy(true);
        setError(null);
        try {
            await markAllRead();
            load();
        } catch (caught) {
            setError(toApiError(caught).message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="mx-auto max-w-2xl space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">Notifications</h1>
                {result && result.meta.unread_count > 0 && (
                    <button
                        type="button" onClick={readAll} disabled={busy}
                        className="min-h-11 rounded-md border border-slate-300 px-4 text-sm font-medium hover:bg-slate-100 focus:outline-2 focus:outline-blue-600 disabled:opacity-60"
                    >
                        {busy ? 'Marking…' : 'Mark all as read'}
                    </button>
                )}
            </div>
            {error && <Alert kind="error">{error}</Alert>}
            {!result && !error && <p className="text-slate-600">Loading…</p>}
            {result && result.data.length === 0 && <p className="text-slate-600">You have no notifications yet.</p>}
            {result && result.data.length > 0 && (
                <ul className="divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                    {result.data.map((notification) => (
                        <li key={notification.id}>
                            <button
                                type="button" onClick={() => void open(notification)}
                                className={`block w-full p-4 text-left hover:bg-slate-50 focus:outline-2 focus:outline-blue-600 ${notification.read_at ? '' : 'bg-blue-50'}`}
                            >
                                <p className={notification.read_at ? '' : 'font-semibold'}>
                                    {!notification.read_at && <span className="mr-2 rounded bg-blue-700 px-1.5 py-0.5 text-xs font-semibold text-white">New</span>}
                                    {notification.message}
                                </p>
                                <p className="mt-1 text-sm text-slate-600">{formatDateTime(notification.created_at)}</p>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {result && result.meta.last_page > 1 && (
                <nav aria-label="Pages" className="flex items-center gap-3">
                    <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="min-h-11 rounded-md border border-slate-300 px-4 disabled:opacity-50">Newer</button>
                    <span className="text-sm">Page {result.meta.current_page} of {result.meta.last_page}</span>
                    <button type="button" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)} className="min-h-11 rounded-md border border-slate-300 px-4 disabled:opacity-50">Older</button>
                </nav>
            )}
        </section>
    );
}
