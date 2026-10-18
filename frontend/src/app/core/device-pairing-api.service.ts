import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, timeout } from 'rxjs';
import { environment } from '../../environments/environment';
import { PageResponse } from './family-api.service';

export interface PairingInvitation {
  code: string;
  expires_at: string;
  child: { id: number; display_name: string };
}
export interface PairedDevice {
  id: number;
  public_id: string;
  name: string;
  android_version: string | null;
  revoked_at: string | null;
  last_seen_at: string | null;
  child?: { id: number; display_name: string };
}
@Injectable({ providedIn: 'root' })
export class DevicePairingApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl.replace(/\/+$/, '') + '/families';
  private familyUrl(familyId: number): string {
    if (!Number.isSafeInteger(familyId) || familyId <= 0) throw new Error('Invalid family ID');
    return this.baseUrl + '/' + familyId;
  }
  createInvitation(familyId: number, childId: number): Observable<PairingInvitation> {
    if (!Number.isSafeInteger(childId) || childId <= 0) throw new Error('Invalid child ID');
    return this.http
      .post<PairingInvitation>(this.familyUrl(familyId) + '/children/' + childId + '/pairings', {})
      .pipe(timeout(10000));
  }
  listDevices(familyId: number, page = 1): Observable<PageResponse<PairedDevice>> {
    return this.http
      .get<PageResponse<PairedDevice>>(this.familyUrl(familyId) + '/devices', {
        params: { page },
      })
      .pipe(timeout(10000));
  }
  revokeDevice(familyId: number, publicId: string): Observable<{ message: string }> {
    return this.http
      .delete<{ message: string }>(
        this.familyUrl(familyId) + '/devices/' + encodeURIComponent(publicId),
      )
      .pipe(timeout(10000));
  }
}
