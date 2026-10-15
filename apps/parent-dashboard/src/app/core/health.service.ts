import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { timeout } from 'rxjs';
import { environment } from '../../environments/environment';
@Injectable({ providedIn: 'root' })
export class HealthService {
  private http = inject(HttpClient);
  check() {
    return this.http.get<{ status: 'ok' }>(environment.apiUrl + '/health').pipe(timeout(5000));
  }
}
