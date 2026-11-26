import { createBrowserRouter, Navigate } from 'react-router';
import { ChoosePasswordPage } from '../features/auth/ChoosePasswordPage';
import { ForgotPasswordPage } from '../features/auth/ForgotPasswordPage';
import { SignInPage } from '../features/auth/SignInPage';
import { AccountsPage } from '../features/admin/AccountsPage';
import { AdministratorLandingPage } from '../features/admin/AdministratorLandingPage';
import { AuditLogPage } from '../features/admin/AuditLogPage';
import { CategoriesPage } from '../features/admin/CategoriesPage';
import { LocationsPage } from '../features/admin/LocationsPage';
import { SlaTargetsPage } from '../features/admin/SlaTargetsPage';
import { ManagerLandingPage } from '../features/manager/ManagerLandingPage';
import { ResidentLandingPage } from '../features/resident/ResidentLandingPage';
import { MyProfilePage } from '../features/technician/MyProfilePage';
import { TechnicianLandingPage } from '../features/technician/TechnicianLandingPage';
import { TechniciansPage } from '../features/technicians/TechniciansPage';
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
    { path: '/manager', element: <RoleShell role="manager" />, children: [
        { index: true, element: <ManagerLandingPage /> },
        { path: 'technicians', element: <TechniciansPage /> },
    ] },
    { path: '/technician', element: <RoleShell role="technician" />, children: [
        { index: true, element: <TechnicianLandingPage /> },
        { path: 'profile', element: <MyProfilePage /> },
    ] },
    { path: '/admin', element: <RoleShell role="administrator" />, children: [
        { index: true, element: <AdministratorLandingPage /> },
        { path: 'users', element: <AccountsPage /> },
        { path: 'locations', element: <LocationsPage /> },
        { path: 'categories', element: <CategoriesPage /> },
        { path: 'sla-targets', element: <SlaTargetsPage /> },
        { path: 'technicians', element: <TechniciansPage /> },
        { path: 'audit-logs', element: <AuditLogPage /> },
    ] },
    { path: '*', element: <Home /> },
]);
