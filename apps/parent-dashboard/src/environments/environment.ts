import type { FirebaseOptions } from 'firebase/app';

// Configure the Firebase web application locally. Never add service-account secrets here.
export const environment: {
  production: boolean;
  apiUrl: string;
  firebaseConfig: FirebaseOptions | null;
} = {
  production: false,
  apiUrl: 'http://localhost:8000/api/v1',
  firebaseConfig: null,
};
