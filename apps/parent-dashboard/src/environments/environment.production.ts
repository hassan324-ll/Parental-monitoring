import type { FirebaseOptions } from 'firebase/app';

// Without real provider configuration, authentication stays disabled.
export const environment: {
  production: boolean;
  apiUrl: string;
  firebaseConfig: FirebaseOptions | null;
} = {
  production: true,
  apiUrl: '/api/v1',
  firebaseConfig: null,
};
