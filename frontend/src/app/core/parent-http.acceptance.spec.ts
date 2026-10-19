import { TestBed } from '@angular/core/testing';
import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withFetch,
  withInterceptors,
} from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { inject as fixtureValue } from 'vitest';
import { FirebaseAuthService } from './firebase-auth.service';
import { authTokenInterceptor } from './auth-token.interceptor';
import { SharingSummaryApiService } from './sharing-summary-api.service';
import { environment } from '../../environments/environment';
interface ParentFixture {
  url: string;
  family: number;
  other_family: number;
  device: string;
  tokens: { owner: string; viewer: string; other: string; unverified: string; expired: string };
}
declare module 'vitest' {
  interface ProvidedContext {
    familyguardParentHttp: ParentFixture | null;
  }
}
const fixture = fixtureValue('familyguardParentHttp');
describe.skipIf(!fixture)('opt-in signed parent client to real Laravel HTTP', () => {
  it('validates real signature/claims, family roles, minimized summary and parent revocation', async () => {
    if (!fixture)
      throw new Error('Use scripts/test-parent-http.ps1 with isolated synthetic fixture.');
    const url = new URL(fixture.url);
    if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || !url.port)
      throw new Error('Parent acceptance requires loopback HTTP.');
    const original = environment.apiUrl;
    environment.apiUrl = fixture.url + '/api/v1';
    let token: string | null = fixture.tokens.owner;
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withFetch(), withInterceptors([authTokenInterceptor])),
        { provide: FirebaseAuthService, useValue: { idToken: async () => token } },
      ],
    });
    const api = TestBed.inject(SharingSummaryApiService);
    const http = TestBed.inject(HttpClient);
    const deny = async (action: () => Promise<unknown>, status: number) => {
      try {
        await action();
        throw new Error('Expected protected request denial.');
      } catch (error) {
        if (!(error instanceof HttpErrorResponse) || error.status !== status)
          throw new Error(
            'Protected request returned unexpected status; inspect local redacted acceptance logs.',
          );
      }
    };
    try {
      const saved = await firstValueFrom(api.read(fixture.family, fixture.device));
      expect(saved.collection_available).toBe(false);
      expect(saved.usage.days[0]?.total_seconds).toBe(60);
      expect(saved.location.latest?.longitude).toBe(0.01);
      expect(saved.location.latest?.accuracy_meters).toBe(1000);
      token = fixture.tokens.viewer;
      await deny(() => firstValueFrom(api.read(fixture.family, fixture.device)), 403);
      token = fixture.tokens.other;
      await deny(() => firstValueFrom(api.read(fixture.family, fixture.device)), 404);
      token = fixture.tokens.owner;
      await deny(() => firstValueFrom(api.read(fixture.other_family, fixture.device)), 404);
      for (const invalid of [
        null,
        fixture.tokens.unverified,
        fixture.tokens.expired,
        fixture.tokens.owner.slice(0, -20) + 'invalid-signature',
      ]) {
        token = invalid;
        await deny(() => firstValueFrom(api.read(fixture.family, fixture.device)), 401);
      }
      token = fixture.tokens.owner;
      await firstValueFrom(
        http.delete(
          environment.apiUrl + '/families/' + fixture.family + '/devices/' + fixture.device,
        ),
      );
      const revoked = await firstValueFrom(api.read(fixture.family, fixture.device));
      expect(revoked.revoked).toBe(true);
      expect(revoked.usage.days).toEqual([]);
      expect(revoked.location.latest).toBeNull();
    } finally {
      environment.apiUrl = original;
      TestBed.resetTestingModule();
    }
  }, 60000);
});
