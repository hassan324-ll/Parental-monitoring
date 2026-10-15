import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { ParentProfileService } from './parent-profile.service';
import { environment } from '../../environments/environment';

describe('parent profile API contract', () => {
  it('loads a profile from the authenticated backend endpoint', () => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    const service = TestBed.inject(ParentProfileService);
    const http = TestBed.inject(HttpTestingController);
    let name = '';

    service.load().subscribe((response) => (name = response.data.name));
    const request = http.expectOne(environment.apiUrl + '/me');
    expect(request.request.method).toBe('GET');
    request.flush({ data: { id: 1, name: 'Synthetic Parent', email: 'parent@example.test' } });
    expect(name).toBe('Synthetic Parent');
    http.verify();
  });
});
