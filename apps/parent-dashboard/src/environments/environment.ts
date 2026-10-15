import type { FirebaseOptions } from 'firebase/app';

// Firebase WEB settings are public project identifiers (not Admin credentials).
// Configure API key restrictions, authorized domains, and abuse protections in
// the Firebase/Google Cloud consoles. This configuration is for local testing.
export const environment: {
  production: boolean;
  apiUrl: string;
  firebaseConfig: FirebaseOptions | null;
} = {
  production: false,
  apiUrl: 'http://localhost:8000/api/v1',
  firebaseConfig: {
    apiKey: 'AIzaSyCgi7lQF4yZ5O5cKvYBstgbWV4ejVXODSA',
    authDomain: 'family-guard-eb08c.firebaseapp.com',
    projectId: 'family-guard-eb08c',
    storageBucket: 'family-guard-eb08c.firebasestorage.app',
    messagingSenderId: '594749876477',
    appId: '1:594749876477:web:5ae98b1cf721165122f694',
  },
};
