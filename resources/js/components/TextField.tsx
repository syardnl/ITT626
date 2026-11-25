import type { InputHTMLAttributes } from 'react';

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    /** Guidance shown under the field and announced with it. */
    hint?: string;
    /** Field-level error from the API envelope; announced and linked to the input. */
    error?: string;
}

export function TextField({ id, label, hint, error, ...input }: TextFieldProps) {
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [error && errorId, hint && hintId].filter(Boolean).join(' ') || undefined;

    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium">{label}</label>
            <input
                id={id}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="block min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 focus:outline-2 focus:outline-blue-600 aria-invalid:border-red-600"
                {...input}
            />
            {error && <p id={errorId} role="alert" className="text-sm text-red-700">{error}</p>}
            {hint && <p id={hintId} className="text-sm text-slate-600">{hint}</p>}
        </div>
    );
}
