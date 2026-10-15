import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import { FIREBASE_CLIENT, FirebaseAuthService } from './firebase-auth.service';
const sdk = {
  initializeApp: vi.fn(),
  getApps: vi.fn(),
  getAuth: vi.fn(),
  reload: vi.fn(),
  signOut: vi.fn(),
  signInWithEmailAndPassword: vi.fn(),
  createUserWithEmailAndPassword: vi.fn(),
  sendEmailVerification: vi.fn(),
  sendPasswordResetEmail: vi.fn(),
  updateProfile: vi.fn(),
};
describe('Firebase client fail-closed lifecycle', () => {
  const original = environment.firebaseConfig;
  let user: { emailVerified: boolean; getIdToken: ReturnType<typeof vi.fn> };
  let auth: { currentUser: typeof user | null; authStateReady: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    vi.resetAllMocks();
    environment.firebaseConfig = {
      apiKey: 'synthetic-public-api-key',
      projectId: 'synthetic-project',
    };
    user = { emailVerified: true, getIdToken: vi.fn().mockResolvedValue('synthetic-token') };
    auth = { currentUser: user, authStateReady: vi.fn().mockResolvedValue(undefined) };
    sdk.getApps.mockReturnValue([]);
    sdk.initializeApp.mockReturnValue({ name: 'familyguard-parent' });
    sdk.getAuth.mockReturnValue(auth);
    sdk.reload.mockResolvedValue(undefined);
    sdk.signOut.mockResolvedValue(undefined);
    sdk.signInWithEmailAndPassword.mockResolvedValue({ user });
    sdk.createUserWithEmailAndPassword.mockResolvedValue({ user });
    sdk.updateProfile.mockResolvedValue(undefined);
    sdk.sendEmailVerification.mockResolvedValue(undefined);
    sdk.sendPasswordResetEmail.mockResolvedValue(undefined);
    TestBed.configureTestingModule({ providers: [{ provide: FIREBASE_CLIENT, useValue: sdk }] });
  });
  afterEach(() => {
    environment.firebaseConfig = original;
  });
  const service = () => TestBed.inject(FirebaseAuthService);
  it('disables auth when configuration is absent', async () => {
    environment.firebaseConfig = null;
    const s = service();
    expect(s.configured).toBe(false);
    expect(await s.verifiedUser()).toBeNull();
    expect(await s.idToken()).toBeNull();
    await expect(s.login('parent@example.test', 'test')).rejects.toThrow('not configured');
    expect(sdk.initializeApp).not.toHaveBeenCalled();
  });
  it('disables auth rather than crashing on malformed public config', () => {
    sdk.getAuth.mockImplementation(() => {
      throw new Error('invalid-api-key');
    });
    expect(service().configured).toBe(false);
  });
  it('rejects unverified users and signs out', async () => {
    user.emailVerified = false;
    await expect(service().login('parent@example.test', 'test')).rejects.toThrow(
      'verify your email',
    );
    expect(sdk.signOut).toHaveBeenCalledWith(auth);
    expect(await service().verifiedUser()).toBeNull();
    expect(await service().idToken()).toBeNull();
  });
  it('clears a partial login when provider reload fails', async () => {
    sdk.reload.mockRejectedValue(new Error('offline'));
    await expect(service().login('parent@example.test', 'test')).rejects.toThrow('offline');
    expect(sdk.signOut).toHaveBeenCalledWith(auth);
  });
  it('registers, sends verification and ends the unverified session', async () => {
    await service().register(' Synthetic ', ' parent@example.test ', 'test-password');
    expect(sdk.createUserWithEmailAndPassword).toHaveBeenCalledWith(
      auth,
      'parent@example.test',
      'test-password',
    );
    expect(sdk.updateProfile).toHaveBeenCalledWith(user, { displayName: 'Synthetic' });
    expect(sdk.sendEmailVerification).toHaveBeenCalledWith(user);
    expect(sdk.signOut).toHaveBeenCalledWith(auth);
  });
  it('signs out even when verification delivery fails', async () => {
    sdk.sendEmailVerification.mockRejectedValue(new Error('email unavailable'));
    await expect(
      service().register('Synthetic', 'parent@example.test', 'test-password'),
    ).rejects.toThrow();
    expect(sdk.signOut).toHaveBeenCalledWith(auth);
  });
  it('waits for restored identity and returns tokens only for verified users', async () => {
    expect(await service().verifiedUser()).toBe(user);
    expect(await service().idToken()).toBe('synthetic-token');
    expect(auth.authStateReady).toHaveBeenCalled();
    auth.currentUser = null;
    expect(await service().idToken()).toBeNull();
  });
  it('delegates password reset and logout to Firebase', async () => {
    await service().resetPassword(' parent@example.test ');
    expect(sdk.sendPasswordResetEmail).toHaveBeenCalledWith(auth, 'parent@example.test');
    await service().logout();
    expect(sdk.signOut).toHaveBeenCalledWith(auth);
  });

  it('does not restore access after logout during a pending guard reload', async () => {
    sdk.reload.mockImplementation(async () => {
      auth.currentUser = null;
    });
    expect(await service().verifiedUser()).toBeNull();
  });
  it('refreshes an old ID token after newly verified email metadata', async () => {
    user.emailVerified = false;
    sdk.reload.mockImplementation(async () => {
      user.emailVerified = true;
    });
    expect(await service().verifiedUser()).toBe(user);
    expect(user.getIdToken).toHaveBeenCalledWith(true);
  });
});
