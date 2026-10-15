import { TestBed } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { FamilyApiService } from './family-api.service';
import { environment } from '../../environments/environment';

describe('protected family HTTP contracts', () => {
  let api: FamilyApiService;
  let http: HttpTestingController;
  const url = environment.apiUrl + '/families';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(FamilyApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists only families returned by the authorized API', () => {
    let names: string[] = [];
    api.listFamilies().subscribe((res) => (names = res.data.map((family) => family.name)));
    const request = http.expectOne((r) => r.url === url && r.params.get('page') === '1');
    expect(request.request.method).toBe('GET');
    request.flush({
      data: [{ id: 1, name: 'Synthetic Family', owner_id: 1 }],
      meta: { current_page: 1, last_page: 1, total: 1 },
    });
    expect(names).toEqual(['Synthetic Family']);
  });

  it('creates families without client-specified ownership', () => {
    api.createFamily(' Test Household ').subscribe();
    const request = http.expectOne(url);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: 'Test Household' });
    request.flush({ data: { id: 1, name: 'Test Household', owner_id: 1 } });
  });

  it('scopes child listing and creation to one family', () => {
    api.listChildren(7, 2).subscribe();
    const get = http.expectOne((r) => r.url === url + '/7/children' && r.params.get('page') === '2');
    expect(get.request.method).toBe('GET');
    get.flush({ data: [], meta: { current_page: 2, last_page: 2, total: 21 } });

    api.createChild(7, ' Example Child ').subscribe();
    const post = http.expectOne(url + '/7/children');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual({ display_name: 'Example Child' });
    post.flush({ data: { id: 1, family_id: 7, display_name: 'Example Child' } });
  });

  it('refuses invalid family IDs before sending HTTP requests', () => {
    expect(() => api.listChildren(0)).toThrowError('Invalid family ID');
    expect(() => api.createChild(-1, 'Invalid')).toThrowError('Invalid family ID');
  });
});
