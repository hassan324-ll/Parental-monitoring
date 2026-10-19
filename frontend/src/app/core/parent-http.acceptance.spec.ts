import { TestBed } from '@angular/core/testing';
import {
  HttpBackend,
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
import { DevicePairingApiService } from './device-pairing-api.service';
import { SharingSummaryApiService } from './sharing-summary-api.service';
import { environment } from '../../environments/environment';
interface ParentFixture {
  url: string;
  family: number;
  other_family: number;
  device: string;
  child: number;
  expired_code: string;
  candidate: string;
  candidate_hash: string;
  replacement: string;
  replacement_hash: string;
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
  it('pairs with explicit device approval, rotates credentials and enforces real parent revocation', async () => {
    if (!fixture) throw new Error('Use the isolated parent HTTP runner.');
    const base = new URL(fixture.url);
    if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port)
      throw new Error('Loopback HTTP required.');
    const original = environment.apiUrl;
    environment.apiUrl = fixture.url + '/api/v1';
    let parentToken = fixture.tokens.owner;
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withFetch(), withInterceptors([authTokenInterceptor])),
        { provide: FirebaseAuthService, useValue: { idToken: async () => parentToken } },
      ],
    });
    const pairing = TestBed.inject(DevicePairingApiService);
    // The device channel deliberately has no Firebase-parent interceptor.
    const device = new HttpClient(TestBed.inject(HttpBackend));
    const endpoint = (path: string) => environment.apiUrl + '/' + path;
    const bearer = (credential: string) => ({ headers: { Authorization: 'Bearer ' + credential } });
    const deny = async (work: () => Promise<unknown>, status: number) => {
      try {
        await work();
        throw new Error('Expected request denial.');
      } catch (error) {
        if (!(error instanceof HttpErrorResponse) || error.status !== status)
          throw new Error(
            'Pairing acceptance received unexpected status; inspect redacted local logs.',
          );
      }
    };
    try {
      parentToken = fixture.tokens.viewer;
      await deny(
        () => firstValueFrom(pairing.createInvitation(fixture.family, fixture.child)),
        403,
      );
      parentToken = fixture.tokens.other;
      await deny(
        () => firstValueFrom(pairing.createInvitation(fixture.family, fixture.child)),
        404,
      );
      parentToken = fixture.tokens.owner;
      const invitation = await firstValueFrom(
        pairing.createInvitation(fixture.family, fixture.child),
      );
      expect(invitation.child.id).toBe(fixture.child);
      const preview = await firstValueFrom(
        device.post<{ requires_approval: boolean }>(endpoint('device-pairings/preview'), {
          code: invitation.code,
        }),
      );
      expect(preview.requires_approval).toBe(true);
      const approval = {
        code: invitation.code,
        device_name: 'HTTP staged phone',
        android_version: '26',
        approved: true,
        credential_hash: fixture.candidate_hash,
      };
      await deny(
        () =>
          firstValueFrom(
            device.post(endpoint('device-pairings/approve'), { ...approval, approved: false }),
          ),
        422,
      );
      const result = await firstValueFrom(
        device.post<{ device: { id: string }; device_token?: string }>(
          endpoint('device-pairings/approve'),
          approval,
        ),
      );
      expect(Object.hasOwn(result, 'device_token')).toBe(false);
      const publicId = result.device.id;
      const paired = await firstValueFrom(
        device.get<{ paired: boolean }>(endpoint('companion/me'), bearer(fixture.candidate)),
      );
      expect(paired.paired).toBe(true);
      await deny(
        () => firstValueFrom(device.get(endpoint('companion/me'), bearer(parentToken))),
        401,
      );
      await deny(
        () => firstValueFrom(device.post(endpoint('device-pairings/approve'), approval)),
        404,
      );
      await deny(
        () =>
          firstValueFrom(
            device.post(endpoint('device-pairings/approve'), {
              ...approval,
              code: fixture.expired_code,
            }),
          ),
        404,
      );
      const rotated = await firstValueFrom(
        device.post<{ rotated: boolean }>(
          endpoint('companion/rotate'),
          { next_credential_hash: fixture.replacement_hash },
          bearer(fixture.candidate),
        ),
      );
      expect(rotated.rotated).toBe(true);
      await deny(
        () => firstValueFrom(device.get(endpoint('companion/me'), bearer(fixture.candidate))),
        401,
      );
      const status = await firstValueFrom(
        device.get<{ paired: boolean }>(endpoint('companion/me'), bearer(fixture.replacement)),
      );
      expect(status.paired).toBe(true);
      const list = await firstValueFrom(pairing.listDevices(fixture.family));
      expect(
        list.data.some((value) => value.public_id === publicId && value.revoked_at === null),
      ).toBe(true);
      parentToken = fixture.tokens.viewer;
      await deny(() => firstValueFrom(pairing.revokeDevice(fixture.family, publicId)), 403);
      parentToken = fixture.tokens.other;
      await deny(() => firstValueFrom(pairing.revokeDevice(fixture.family, publicId)), 404);
      parentToken = fixture.tokens.owner;
      await firstValueFrom(pairing.revokeDevice(fixture.family, publicId));
      await deny(
        () => firstValueFrom(device.get(endpoint('companion/me'), bearer(fixture.replacement))),
        401,
      );
      await deny(
        () =>
          firstValueFrom(
            device.post(
              endpoint('companion/rotate'),
              { next_credential_hash: fixture.candidate_hash },
              bearer(fixture.replacement),
            ),
          ),
        401,
      );
      const revoked = await firstValueFrom(pairing.listDevices(fixture.family));
      expect(
        revoked.data.some((value) => value.public_id === publicId && value.revoked_at !== null),
      ).toBe(true);
    } finally {
      environment.apiUrl = original;
      TestBed.resetTestingModule();
    }
  }, 60000);
});
