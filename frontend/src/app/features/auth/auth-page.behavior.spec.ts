import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { AuthPage } from './auth-page';
import { FirebaseAuthService } from '../../core/firebase-auth.service';
describe('parent authentication forms', () => {
  let auth: {
    configured: boolean;
    login: ReturnType<typeof vi.fn>;
    register: ReturnType<typeof vi.fn>;
    resetPassword: ReturnType<typeof vi.fn>;
  };
  beforeEach(async () => {
    auth = {
      configured: true,
      login: vi.fn().mockResolvedValue(undefined),
      register: vi.fn().mockResolvedValue(undefined),
      resetPassword: vi.fn().mockResolvedValue(undefined),
    };
    await TestBed.configureTestingModule({
      imports: [AuthPage],
      providers: [provideRouter([]), { provide: FirebaseAuthService, useValue: auth }],
    }).compileComponents();
    vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });
  function fixture() {
    const f = TestBed.createComponent(AuthPage);
    f.detectChanges();
    return f;
  }
  it('accepts existing provider passwords shorter than registration policy', async () => {
    const f = fixture();
    f.componentInstance.form.patchValue({ email: 'parent@example.test', password: 'six123' });
    await f.componentInstance.submit();
    expect(auth.login).toHaveBeenCalledWith('parent@example.test', 'six123');
  });
  it('rejects whitespace-only registration names', async () => {
    const f = fixture();
    f.componentInstance.setMode('register');
    f.componentInstance.form.setValue({
      name: '   ',
      email: 'parent@example.test',
      password: 'strong-test-password',
    });
    await f.componentInstance.submit();
    expect(auth.register).not.toHaveBeenCalled();
  });
  it('rejects malformed email and short registration passwords', async () => {
    const f = fixture();
    f.componentInstance.setMode('register');
    f.componentInstance.form.setValue({ name: 'Synthetic', email: 'bad', password: 'short' });
    await f.componentInstance.submit();
    expect(auth.register).not.toHaveBeenCalled();
  });
  it('prevents duplicate login submissions and mode changes while busy', async () => {
    let finish!: () => void;
    auth.login.mockReturnValue(new Promise<void>((r) => (finish = r)));
    const f = fixture();
    f.componentInstance.form.patchValue({
      email: 'parent@example.test',
      password: 'test-password',
    });
    const first = f.componentInstance.submit();
    const second = f.componentInstance.submit();
    f.detectChanges();
    expect(auth.login).toHaveBeenCalledTimes(1);
    expect(
      Array.from(f.nativeElement.querySelectorAll('.actions button')).every(
        (b) => (b as HTMLButtonElement).disabled,
      ),
    ).toBe(true);
    finish();
    await Promise.all([first, second]);
    expect(f.componentInstance.busy()).toBe(false);
  });
  it('explains invalid and empty registration fields after interaction', () => {
    const f = fixture();
    f.componentInstance.setMode('register');
    f.componentInstance.form.markAllAsTouched();
    f.detectChanges();
    const text = f.nativeElement.textContent as string;
    expect(text).toContain('Enter a name of 1–100 characters.');
    expect(text).toContain('Enter a valid email address.');
    expect(text).toContain('Use at least 8 characters for your password.');
    expect(
      (f.nativeElement.querySelector('button[type="submit"]') as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(auth.register).not.toHaveBeenCalled();
  });
  it('renders a safe specific registration failure and releases the busy state', async () => {
    auth.register.mockRejectedValue({
      code: 'auth/email-already-in-use',
      message: 'synthetic-private-provider-message',
    });
    const f = fixture();
    f.componentInstance.setMode('register');
    f.componentInstance.form.setValue({
      name: 'Synthetic',
      email: 'parent@example.test',
      password: 'synthetic-password',
    });
    await f.componentInstance.submit();
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('An account already uses this email');
    expect(f.nativeElement.textContent).not.toContain('synthetic-private-provider-message');
    expect(f.componentInstance.busy()).toBe(false);
  });
  it('keeps password reset responses generic', async () => {
    auth.resetPassword.mockRejectedValue(new Error('user-not-found'));
    const f = fixture();
    f.componentInstance.setMode('reset');
    f.componentInstance.form.controls.email.setValue('absent@example.test');
    await f.componentInstance.submit();
    expect(f.componentInstance.message()).toContain('If an account exists');
    expect(f.componentInstance.error()).toBe('');
  });
});
