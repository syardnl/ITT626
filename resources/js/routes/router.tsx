import { createBrowserRouter, Navigate } from 'react-router';
import { SignInPage } from '../features/auth/SignInPage';
import { AdministratorLandingPage } from '../features/admin/AdministratorLandingPage';
import { ManagerLandingPage } from '../features/manager/ManagerLandingPage';
import { ResidentLandingPage } from '../features/resident/ResidentLandingPage';
import { TechnicianLandingPage } from '../features/technician/TechnicianLandingPage';
import { RoleShell } from '../layouts/RoleShell';
import { landingPath, useAuth } from '../lib/auth';

function Home() {
    const { account, loading } = useAuth();
    if (loading) return null;
    return <Navigate to={account ? landingPath[account.role] : '/login'} replace />;
}

export const router = createBrowserRouter([
    { path: '/', element: <Home /> },
    { path: '/login', element: <SignInPage /> },
    { path: '/resident', element: <RoleShell role="resident" />, children: [{ index: true, element: <ResidentLandingPage /> }] },
    { path: '/manager', element: <RoleShell role="manager" />, children: [{ index: true, element: <ManagerLandingPage /> }] },
    { path: '/technician', element: <RoleShell role="technician" />, children: [{ index: true, element: <TechnicianLandingPage /> }] },
    { path: '/admin', element: <RoleShell role="administrator" />, children: [{ index: true, element: <AdministratorLandingPage /> }] },
    { path: '*', element: <Home /> },
]);
