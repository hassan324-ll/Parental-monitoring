import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { FirebaseAuthService } from './firebase-auth.service';

export const parentAuthGuard: CanActivateFn = async (_route, state) => {
  const auth = inject(FirebaseAuthService);
  const router = inject(Router);
  try {
    if (await auth.verifiedUser()) return true;
  } catch {
    // Fail closed on configuration or connectivity errors.
  }
  return router.createUrlTree(['/auth'], { queryParams: { next: state.url } });
};
