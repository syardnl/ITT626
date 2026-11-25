export function SubmitButton({ busy, label, busyLabel }: { busy: boolean; label: string; busyLabel: string }) {
    return (
        <button
            type="submit" disabled={busy}
            className="min-h-11 w-full rounded-md bg-blue-700 font-medium text-white hover:bg-blue-800 focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 disabled:opacity-60"
        >
            {busy ? busyLabel : label}
        </button>
    );
}
