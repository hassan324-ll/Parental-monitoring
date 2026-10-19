import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { map, timeout } from 'rxjs';
import { environment } from '../../environments/environment';

export interface SavedUsageDay {
  day: string;
  recorded_until: string;
  total_seconds: number;
  apps: { package_name: string; duration_seconds: number }[];
}
export interface SavedSharingSummary {
  device_id: string;
  revoked: boolean;
  collection_available: false;
  usage: { consent_current: boolean; retention: string; days: SavedUsageDay[] };
  location: {
    consent_current: boolean;
    retention: string;
    latest: {
      latitude: number;
      longitude: number;
      accuracy_meters: number;
      recorded_at: string;
    } | null;
  };
}
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
function ensure(value: unknown): asserts value {
  if (!value) throw new Error('Unsupported saved-data response.');
}
function record(value: unknown): Record<string, unknown> {
  ensure(value !== null && typeof value === 'object' && !Array.isArray(value));
  return value as Record<string, unknown>;
}
function flag(value: unknown): boolean {
  ensure(typeof value === 'boolean');
  return value;
}
function integer(value: unknown, min: number, max: number): number {
  ensure(typeof value === 'number' && Number.isSafeInteger(value) && value >= min && value <= max);
  return value;
}
function stamp(value: unknown): string {
  ensure(
    typeof value === 'string' &&
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value) &&
      Number.isFinite(Date.parse(value)) &&
      new Date(value).toISOString() === value.replace('Z', '.000Z'),
  );
  return value;
}
/** Construct only known bounded fields; never retain unknown payload data. */
export function parseSavedSharing(value: unknown, deviceId: string): SavedSharingSummary {
  const root = record(value);
  const usage = record(root['usage']);
  const location = record(root['location']);
  ensure(root['device_id'] === deviceId && root['collection_available'] === false);
  const revoked = flag(root['revoked']);
  const usageConsent = flag(usage['consent_current']);
  const locationConsent = flag(location['consent_current']);
  ensure(
    usage['retention'] === '7 UTC calendar days including today' &&
      location['retention'] === '24 hours',
  );
  const rawDays = usage['days'];
  ensure(Array.isArray(rawDays) && rawDays.length <= 7);
  const dates = new Set<string>();
  const days = rawDays.map((value): SavedUsageDay => {
    const item = record(value);
    const day = item['day'];
    ensure(typeof day === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(day) && !dates.has(day));
    stamp(day + 'T00:00:00Z');
    dates.add(day);
    const until = stamp(item['recorded_until']);
    ensure(Date.parse(until) > Date.parse(day) && Date.parse(until) <= Date.parse(day) + 86400000);
    const rawApps = item['apps'];
    ensure(Array.isArray(rawApps) && rawApps.length <= 100);
    const packages = new Set<string>();
    const apps = rawApps.map((value) => {
      const app = record(value);
      const name = app['package_name'];
      ensure(
        typeof name === 'string' &&
          name.length <= 150 &&
          /^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/.test(name) &&
          !packages.has(name),
      );
      packages.add(name);
      return { package_name: name, duration_seconds: integer(app['duration_seconds'], 1, 86400) };
    });
    const total = integer(item['total_seconds'], 0, 86400);
    ensure(apps.reduce((sum, app) => sum + app.duration_seconds, 0) === total);
    return { day, recorded_until: until, total_seconds: total, apps };
  });
  let latest: SavedSharingSummary['location']['latest'] = null;
  if (location['latest'] !== null) {
    const point = record(location['latest']);
    const lat = point['latitude'];
    const lng = point['longitude'];
    ensure(
      typeof lat === 'number' &&
        Number.isFinite(lat) &&
        Math.abs(lat) <= 90 &&
        Math.abs(Math.round(lat * 100) - lat * 100) < 1e-7,
    );
    ensure(
      typeof lng === 'number' &&
        Number.isFinite(lng) &&
        Math.abs(lng) <= 180 &&
        Math.abs(Math.round(lng * 100) - lng * 100) < 1e-7,
    );
    latest = {
      latitude: lat,
      longitude: lng,
      accuracy_meters: integer(point['accuracy_meters'], 1000, 100000),
      recorded_at: stamp(point['recorded_at']),
    };
  }
  ensure((!revoked && usageConsent) || days.length === 0);
  ensure((!revoked && locationConsent) || latest === null);
  ensure(!revoked || (!usageConsent && !locationConsent));
  return {
    device_id: deviceId,
    revoked,
    collection_available: false,
    usage: { consent_current: usageConsent, retention: usage['retention'], days },
    location: { consent_current: locationConsent, retention: location['retention'], latest },
  };
}
@Injectable({ providedIn: 'root' })
export class SharingSummaryApiService {
  private readonly http = inject(HttpClient);
  read(familyId: number, deviceId: string) {
    ensure(Number.isSafeInteger(familyId) && familyId > 0 && uuid.test(deviceId));
    return this.http
      .get<unknown>(
        environment.apiUrl.replace(/\/+$/, '') +
          '/families/' +
          familyId +
          '/devices/' +
          deviceId +
          '/sharing-summary',
      )
      .pipe(
        timeout(10000),
        map((value) => parseSavedSharing(value, deviceId)),
      );
  }
}
