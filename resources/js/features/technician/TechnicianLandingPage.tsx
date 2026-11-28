import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert } from '../../components/Alert';
import { api, complaintStatusLabel, formatDateTime, priorityLabel, toApiError, type TechnicianTask, type TechnicianTaskGroups } from '../../lib/api';
import { useAuth } from '../../lib/auth';

const groups: { key: keyof TechnicianTaskGroups; title: string; empty: string }[] = [
    { key: 'new', title: 'New', empty: 'No new tasks.' },
    { key: 'in_progress', title: 'In progress', empty: 'Nothing in progress.' },
    { key: 'returned', title: 'Returned', empty: 'Nothing has been sent back to you.' },
    { key: 'completed', title: 'Completed', empty: 'No completed work yet.' },
    { key: 'history', title: 'Reassigned to others', empty: 'Nothing has been reassigned away from you.' },
];

/** A Technician's home page: their task list, most urgent first within each group, then work reassigned to others (read-only). */
export function TechnicianLandingPage() {
    const { account } = useAuth();
    const [tasks, setTasks] = useState<TechnicianTaskGroups | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ data: TechnicianTaskGroups }>('/technician/tasks')
            .then((response) => setTasks(response.data.data))
            .catch((caught) => setError(toApiError(caught).message));
    }, []);

    return (
        <section className="mx-auto max-w-2xl space-y-6">
            <h1 className="text-2xl font-semibold md:text-3xl">Welcome, {account?.name}</h1>
            {error && <Alert kind="error">{error}</Alert>}
            {!tasks && !error && <p className="text-slate-600">Loading your tasks…</p>}
            {tasks && groups.map((group) => (
                <section key={group.key} aria-labelledby={`tasks-${group.key}`}>
                    <h2 id={`tasks-${group.key}`} className="text-lg font-semibold">
                        {group.title} <span className="text-base font-normal text-slate-600">({tasks[group.key].length})</span>
                    </h2>
                    {tasks[group.key].length === 0
                        ? <p className="mt-2 text-sm text-slate-600">{group.empty}</p>
                        : <TaskList tasks={tasks[group.key]} />}
                </section>
            ))}
        </section>
    );
}

function TaskList({ tasks }: { tasks: TechnicianTask[] }) {
    return (
        <ul className="mt-2 divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
            {tasks.map((task) => (
                <li key={task.id}>
                    <Link to={`/technician/complaints/${task.id}`} className="block p-4 hover:bg-slate-50 focus:outline-2 focus:outline-blue-600">
                        <p className="font-medium">{task.title}</p>
                        <p className="mt-1 text-sm">
                            <span className={task.priority === 'critical' ? 'font-semibold text-red-800' : task.priority === 'high' ? 'font-semibold text-amber-900' : 'font-medium'}>
                                {priorityLabel[task.priority]} priority
                            </span>
                            {` · ${task.location.name} (${task.location.code})`}
                        </p>
                        <p className="mt-1 text-sm text-slate-600">
                            {task.reference_no} · {complaintStatusLabel[task.status]} · assigned {formatDateTime(task.assigned_at)}
                        </p>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
