import { api, ensureCsrfCookie } from './api';

/** One in-app notification; `link` already points into the recipient's own section. */
export interface AppNotification {
    id: string;
    event: string;
    message: string;
    complaint: { id: number; reference_no: string };
    link: string;
    read_at: string | null;
    created_at: string;
}

export interface NotificationPage {
    data: AppNotification[];
    meta: { current_page: number; last_page: number; total: number; unread_count: number };
}

/** Fired whenever this tab marks notifications read, so the bell recounts at once. */
export const notificationsChanged = 'notifications-changed';

export function fetchNotifications(page = 1) {
    return api.get<NotificationPage>('/notifications', { params: { page } }).then((response) => response.data);
}

export async function markRead(id: string) {
    await ensureCsrfCookie();
    await api.post(`/notifications/${id}/read`);
    window.dispatchEvent(new Event(notificationsChanged));
}

export async function markAllRead() {
    await ensureCsrfCookie();
    await api.post('/notifications/read-all');
    window.dispatchEvent(new Event(notificationsChanged));
}
