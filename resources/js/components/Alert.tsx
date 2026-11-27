import type { ReactNode } from 'react';

const tone = {
    error: 'border-red-300 bg-red-50 text-red-800',
    success: 'border-green-300 bg-green-50 text-green-800',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
};

export function Alert({ kind, children }: { kind: keyof typeof tone; children: ReactNode }) {
    return (
        <p role={kind === 'error' ? 'alert' : 'status'} className={`rounded-md border p-3 text-sm ${tone[kind]}`}>
            {children}
        </p>
    );
}
