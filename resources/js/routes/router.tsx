import { createBrowserRouter, Navigate } from 'react-router';
import { ChoosePasswordPage } from '../features/auth/ChoosePasswordPage';
import { ForgotPasswordPage } from '../features/auth/ForgotPasswordPage';
import { SignInPage } from '../features/auth/SignInPage';
import { AdministratorLandingPage } from '../features/admin/AdministratorLandingPage';
import { AuditLogPage } from '../features/admin/AuditLogPage';
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
    // /reset-password and /set-password must match PasswordTokenPurpose::spaPath() on the server.
    { path: '/forgot-password', element: <ForgotPasswordPage /> },
    { path: '/reset-password', element: <ChoosePasswordPage purpose="reset" /> },
    { path: '/set-password', element: <ChoosePasswordPage purpose="setPassword" /> },
    { path: '/resident', element: <RoleShell role="resident" />, children: [{ index: true, element: <ResidentLandingPage /> }] },
    { path: '/manager', element: <RoleShell role="manager" />, children: [{ index: true, element: <ManagerLandingPage /> }] },
    { path: '/technician', element: <RoleShell role="technician" />, children: [{ index: true, element: <TechnicianLandingPage /> }] },
    { path: '/admin', element: <RoleShell role="administrator" />, children: [
        { index: true, element: <AdministratorLandingPage /> },
        { path: 'audit-logs', element: <AuditLogPage /> },
    ] },
    { path: '*', element: <Home /> },
]);
