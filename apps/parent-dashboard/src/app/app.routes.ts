import { Routes } from '@angular/router';
export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
  {
    path: 'auth',
    title: 'Authentication | FamilyGuard',
    data: { title: 'Authentication' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'dashboard',
    title: 'Dashboard | FamilyGuard',
    data: { title: 'Dashboard' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'children',
    title: 'My Children | FamilyGuard',
    data: { title: 'My Children' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'devices',
    title: 'Connected Devices | FamilyGuard',
    data: { title: 'Connected Devices' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'activity',
    title: 'Application Activity | FamilyGuard',
    data: { title: 'Application Activity' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'screen-time',
    title: 'Screen Time | FamilyGuard',
    data: { title: 'Screen Time' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'location',
    title: 'GPS Location | FamilyGuard',
    data: { title: 'GPS Location' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'screen-sharing',
    title: 'Screen Sharing | FamilyGuard',
    data: { title: 'Screen Sharing' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'alerts',
    title: 'Alerts | FamilyGuard',
    data: { title: 'Alerts' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'reports',
    title: 'Reports | FamilyGuard',
    data: { title: 'Reports' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  {
    path: 'settings',
    title: 'Settings | FamilyGuard',
    data: { title: 'Settings' },
    loadComponent: () => import('./features/page').then((m) => m.FeaturePage),
  },
  { path: '**', redirectTo: 'dashboard' },
];
