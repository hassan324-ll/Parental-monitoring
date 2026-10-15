import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { timeout } from 'rxjs';
import { environment } from '../../environments/environment';

export interface ParentProfile {
  id: number;
  name: string;
  email: string;
}

@Injectable({ providedIn: 'root' })
export class ParentProfileService {
  private readonly http = inject(HttpClient);

  load() {
    return this.http
      .get<{ data: ParentProfile }>(environment.apiUrl + '/me')
      .pipe(timeout(10000));
  }
}
