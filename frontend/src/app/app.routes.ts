import { Routes } from '@angular/router';
import { parentAuthGuard } from './core/parent-auth.guard';

const foundationPage = () => import('./features/page').then((m) => m.FeaturePage);

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
  {
    path: 'auth',
    title: 'Parent Sign In | FamilyGuard',
    loadComponent: () => import('./features/auth/auth-page').then((m) => m.AuthPage),
  },
  {
    path: 'children',
    title: 'My Children | FamilyGuard',
    canActivate: [parentAuthGuard],
    loadComponent: () =>
      import('./features/family/family-management').then((m) => m.FamilyManagement),
  },
  {
    path: 'devices',
    title: 'Connected Devices | FamilyGuard',
    canActivate: [parentAuthGuard],
    loadComponent: () => import('./features/devices/device-management').then(m => m.DeviceManagement),
  },
  ...[
    ['dashboard', 'Dashboard'],
    ['activity', 'Application Activity'],
    ['screen-time', 'Screen Time'],
    ['location', 'GPS Location'],
    ['screen-sharing', 'Screen Sharing'],
    ['alerts', 'Alerts'],
    ['reports', 'Reports'],
    ['settings', 'Settings'],
  ].map(([path, title]) => ({
    path,
    title: title + ' | FamilyGuard',
    data: { title },
    canActivate: [parentAuthGuard],
    loadComponent: foundationPage,
  })),
  { path: '**', redirectTo: 'dashboard' },
];
