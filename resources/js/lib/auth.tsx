import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { api, ensureCsrfCookie, type Account, type Role } from './api';

interface AuthState {
    account: Account | null;
    loading: boolean;
    signIn: (email: string, password: string) => Promise<Account>;
    signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthState | null>(null);

export const landingPath: Record<Role, string> = {
    resident: '/resident',
    manager: '/manager',
    technician: '/technician',
    administrator: '/admin',
};

export function AuthProvider({ children }: { children: ReactNode }) {
    const [account, setAccount] = useState<Account | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get<{ data: Account }>('/me')
            .then((res) => setAccount(res.data.data))
            .catch(() => setAccount(null))
            .finally(() => setLoading(false));
    }, []);

    const signIn = useCallback(async (email: string, password: string) => {
        await ensureCsrfCookie();
        const res = await api.post<{ data: Account }>('/auth/login', { email, password });
        setAccount(res.data.data);
        return res.data.data;
    }, []);

    const signOut = useCallback(async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            setAccount(null);
        }
    }, []);

    return <AuthContext.Provider value={{ account, loading, signIn, signOut }}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
    return ctx;
}
