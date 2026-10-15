import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { authTokenInterceptor } from './auth-token.interceptor';
import { FirebaseAuthService } from './firebase-auth.service';
import { environment } from '../../environments/environment';
describe('API token isolation', () => {
  const originalApi = environment.apiUrl;
  let tokens: ReturnType<typeof vi.fn>;
  let http: HttpClient;
  let controller: HttpTestingController;
  beforeEach(() => {
    tokens = vi.fn().mockResolvedValue('synthetic-token');
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authTokenInterceptor])),
        provideHttpClientTesting(),
        { provide: FirebaseAuthService, useValue: { idToken: tokens } },
      ],
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
  });
  afterEach(() => {
    controller.verify();
    environment.apiUrl = originalApi;
  });
  async function request(url: string) {
    http.get(url).subscribe();
    await Promise.resolve();
    return controller.expectOne(url);
  }
  it('attaches a token to the configured API', async () => {
    const r = await request(originalApi + '/families');
    expect(r.request.headers.get('Authorization')).toBe('Bearer synthetic-token');
    r.flush({});
  });
  it.each([
    'https://other.example/api/v1/families',
    'http://localhost:8000.evil.example/api/v1/families',
    'http://localhost:8000/api/v10/families',
    'http://localhost:8000/api/v1/../outside',
  ])('does not expose tokens to %s', async (url) => {
    const r = await request(url);
    expect(r.request.headers.has('Authorization')).toBe(false);
    expect(tokens).not.toHaveBeenCalled();
    r.flush({});
  });
  it('supports the same-origin production API without token leakage', async () => {
    environment.apiUrl = '/api/v1';
    const safe = await request('/api/v1/families');
    expect(safe.request.headers.get('Authorization')).toBe('Bearer synthetic-token');
    safe.flush({});
    tokens.mockClear();
    const unsafe = await request('/api/v1/../../outside');
    expect(unsafe.request.headers.has('Authorization')).toBe(false);
    expect(tokens).not.toHaveBeenCalled();
    unsafe.flush({});
  });
  it('leaves requests unauthenticated when no verified token exists', async () => {
    tokens.mockResolvedValue(null);
    const r = await request(originalApi + '/families');
    expect(r.request.headers.has('Authorization')).toBe(false);
    r.flush({});
  });
});
