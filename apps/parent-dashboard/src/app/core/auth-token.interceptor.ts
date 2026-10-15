import { inject } from '@angular/core';
import { HttpInterceptorFn } from '@angular/common/http';
import { from, switchMap } from 'rxjs';
import { FirebaseAuthService } from './firebase-auth.service';
import { environment } from '../../environments/environment';

// Only attach ID tokens to our own API origin, never to external URLs.
// The backend must independently verify ID tokens before serving private data.
export const authTokenInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(FirebaseAuthService);
  const prefix = environment.apiUrl.replace(/\/+$/, '') + '/';
  if (!request.url.startsWith(prefix)) return next(request);
  return from(auth.idToken()).pipe(
    switchMap((token) =>
      next(token ? request.clone({ setHeaders: { Authorization: 'Bearer ' + token } }) : request),
    ),
  );
};
