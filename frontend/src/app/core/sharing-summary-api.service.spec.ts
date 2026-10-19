import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { parseSavedSharing, SharingSummaryApiService } from './sharing-summary-api.service';
import { environment } from '../../environments/environment';

export const syntheticId = '11111111-1111-4111-8111-111111111111';
export const syntheticSummary = () => ({
  device_id: syntheticId,
  revoked: false,
  collection_available: false,
  usage: {
    consent_current: true,
    retention: '7 UTC calendar days including today',
    days: [
      {
        day: '2026-10-19',
        recorded_until: '2026-10-19T11:00:00Z',
        total_seconds: 60,
        apps: [{ package_name: 'example.synthetic', duration_seconds: 60 }],
      },
    ],
  },
  location: {
    consent_current: true,
    retention: '24 hours',
    latest: {
      latitude: 0,
      longitude: 0.01,
      accuracy_meters: 1000,
      recorded_at: '2026-10-19T11:00:00Z',
    },
  },
});
describe('saved summary HTTP boundary', () => {
  let http: HttpTestingController;
  let api: SharingSummaryApiService;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(SharingSummaryApiService);
  });
  afterEach(() => http.verify());
  it('uses one scoped GET and constructs only the documented fields', () => {
    let result: unknown;
    api.read(7, syntheticId).subscribe((value) => (result = value));
    const request = http.expectOne(
      environment.apiUrl + '/families/7/devices/' + syntheticId + '/sharing-summary',
    );
    expect(request.request.method).toBe('GET');
    expect(request.request.body).toBeNull();
    request.flush({ ...syntheticSummary(), credential: 'synthetic-unwanted' });
    expect(result).toEqual(syntheticSummary());
  });
  it('rejects invalid scope before HTTP', () => {
    for (const family of [0, -1, 1.5, NaN]) expect(() => api.read(family, syntheticId)).toThrow();
    expect(() => api.read(1, '../other')).toThrow();
  });
  it('rejects cross-device and unsupported collection capability', () => {
    expect(() =>
      parseSavedSharing({ ...syntheticSummary(), device_id: 'other' }, syntheticId),
    ).toThrow();
    expect(() =>
      parseSavedSharing({ ...syntheticSummary(), collection_available: true }, syntheticId),
    ).toThrow();
  });
  it('rejects excess precision, invalid typed totals and inconsistent consent', () => {
    const mutations = [
      (x: ReturnType<typeof syntheticSummary>) => (x.location.latest.latitude = 0.00001),
      (x: ReturnType<typeof syntheticSummary>) => (x.location.latest.accuracy_meters = 20),
      (x: ReturnType<typeof syntheticSummary>) =>
        (x.usage.days[0].apps[0].duration_seconds = '60' as unknown as number),
      (x: ReturnType<typeof syntheticSummary>) => (x.usage.days[0].total_seconds = 61),
      (x: ReturnType<typeof syntheticSummary>) => (x.usage.consent_current = false),
      (x: ReturnType<typeof syntheticSummary>) => (x.revoked = true),
      (x: ReturnType<typeof syntheticSummary>) => (x.usage.days[0].day = '2026-02-30'),
      (x: ReturnType<typeof syntheticSummary>) =>
        x.usage.days[0].apps.push(x.usage.days[0].apps[0]),
      (x: ReturnType<typeof syntheticSummary>) => (x.usage.days = Array(8).fill(x.usage.days[0])),
      (x: ReturnType<typeof syntheticSummary>) =>
        (x.usage.days[0].apps = Array(101).fill(x.usage.days[0].apps[0])),
    ];
    for (const mutate of mutations) {
      const value = syntheticSummary();
      mutate(value);
      expect(() => parseSavedSharing(value, syntheticId)).toThrow();
    }
  });
});
