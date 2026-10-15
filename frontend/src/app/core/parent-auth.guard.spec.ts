import { TestBed } from '@angular/core/testing';
import {
  Router,
  provideRouter,
  UrlTree,
  ActivatedRouteSnapshot,
  RouterStateSnapshot,
} from '@angular/router';
import { FirebaseAuthService } from './firebase-auth.service';
import { parentAuthGuard } from './parent-auth.guard';
describe('verified parent guard', () => {
  const verifiedUser = vi.fn();
  beforeEach(() => {
    verifiedUser.mockReset();
    TestBed.configureTestingModule({
      providers: [provideRouter([]), { provide: FirebaseAuthService, useValue: { verifiedUser } }],
    });
  });
  const check = () =>
    TestBed.runInInjectionContext(() =>
      parentAuthGuard({} as ActivatedRouteSnapshot, { url: '/reports' } as RouterStateSnapshot),
    );
  it('allows verified identities', async () => {
    verifiedUser.mockResolvedValue({ emailVerified: true });
    expect(await check()).toBe(true);
  });
  it.each(['missing', 'network failure'])('redirects on %s', async (reason) => {
    if (reason === 'missing') verifiedUser.mockResolvedValue(null);
    else verifiedUser.mockRejectedValue(new Error('offline'));
    const result = await check();
    expect(result instanceof UrlTree).toBe(true);
    expect(TestBed.inject(Router).serializeUrl(result as UrlTree)).toBe('/auth?next=%2Freports');
  });
});
