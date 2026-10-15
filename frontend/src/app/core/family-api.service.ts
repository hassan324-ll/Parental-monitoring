import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, timeout } from 'rxjs';
import { environment } from '../../environments/environment';

export interface Family {
  id: number;
  name: string;
  owner_id: number;
  children_count?: number;
  devices_count?: number;
}

export interface ChildProfile {
  id: number;
  family_id: number;
  display_name: string;
}

export interface PageResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
  };
}

@Injectable({ providedIn: 'root' })
export class FamilyApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl.replace(/\/+$/, '') + '/families';

  listFamilies(page = 1): Observable<PageResponse<Family>> {
    return this.http
      .get<PageResponse<Family>>(this.baseUrl, { params: { page } })
      .pipe(timeout(10000));
  }

  createFamily(name: string): Observable<{ data: Family }> {
    return this.http
      .post<{ data: Family }>(this.baseUrl, { name: name.trim() })
      .pipe(timeout(10000));
  }

  listChildren(familyId: number, page = 1): Observable<PageResponse<ChildProfile>> {
    return this.http
      .get<PageResponse<ChildProfile>>(this.familyChildrenUrl(familyId), { params: { page } })
      .pipe(timeout(10000));
  }

  createChild(familyId: number, displayName: string): Observable<{ data: ChildProfile }> {
    return this.http
      .post<{ data: ChildProfile }>(this.familyChildrenUrl(familyId), {
        display_name: displayName.trim(),
      })
      .pipe(timeout(10000));
  }

  private familyChildrenUrl(familyId: number): string {
    if (!Number.isSafeInteger(familyId) || familyId <= 0) {
      throw new Error('Invalid family ID');
    }
    return this.baseUrl + '/' + familyId + '/children';
  }
}
