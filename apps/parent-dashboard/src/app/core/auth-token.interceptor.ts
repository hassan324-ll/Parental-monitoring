import { DOCUMENT } from '@angular/common';
import { inject } from '@angular/core';
import { HttpInterceptorFn } from '@angular/common/http';
import { from, switchMap } from 'rxjs';
import { FirebaseAuthService } from './firebase-auth.service';
import { environment } from '../../environments/environment';

export const authTokenInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(FirebaseAuthService);
  const document = inject(DOCUMENT);
  let intendedApi = false;
  try {
    const api = new URL(environment.apiUrl, document.baseURI);
    const destination = new URL(request.url, document.baseURI);
    const path = api.pathname.replace(/\/+$/, '');
    intendedApi =
      ['http:', 'https:'].includes(api.protocol) &&
      destination.origin === api.origin &&
      !destination.username &&
      !destination.password &&
      (destination.pathname === path || destination.pathname.startsWith(path + '/'));
  } catch {
    // An invalid destination never receives credentials.
  }
  if (!intendedApi) return next(request);
  return from(auth.idToken()).pipe(
    switchMap((token) =>
      next(token ? request.clone({ setHeaders: { Authorization: 'Bearer ' + token } }) : request),
    ),
  );
};
