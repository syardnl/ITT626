import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router';
import { fetchNotifications, notificationsChanged } from '../lib/notifications';

const REFRESH_MS = 60_000;

/**
 * The header bell: the unread count, fetched on every page change and every minute while
 * signed in (it unmounts on sign-out, which stops the timer). Links to the notifications page.
 */
export function NotificationBell({ to }: { to: string }) {
    const { pathname } = useLocation();
    const [unread, setUnread] = useState(0);

    useEffect(() => {
        let cancelled = false;
        const refresh = () => fetchNotifications()
            .then((page) => { if (!cancelled) setUnread(page.meta.unread_count); })
            .catch(() => { /* keep the last count; the next refresh tries again */ });

        refresh();
        const timer = window.setInterval(refresh, REFRESH_MS);
        window.addEventListener(notificationsChanged, refresh);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            window.removeEventListener(notificationsChanged, refresh);
        };
    }, [pathname]);

    const label = unread === 0 ? 'Notifications, none unread' : `Notifications, ${unread} unread`;

    return (
        <Link
            to={to} aria-label={label} title={label}
            className="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-slate-300 hover:bg-slate-100 focus:outline-2 focus:outline-blue-600"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.7 21a2 2 0 0 1-3.4 0" />
            </svg>
            {unread > 0 && (
                <span aria-hidden="true" className="absolute -right-1.5 -top-1.5 min-w-5 rounded-full bg-red-700 px-1 text-center text-xs font-semibold leading-5 text-white">
                    {unread > 99 ? '99+' : unread}
                </span>
            )}
        </Link>
    );
}
