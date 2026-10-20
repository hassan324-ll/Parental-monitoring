import {
  authenticationErrorMessage,
  firebaseErrorCode,
  RegistrationCompletionError,
} from './authentication-errors';

describe('safe actionable Firebase authentication errors', () => {
  it.each([
    ['auth/email-already-in-use', 'An account already uses this email'],
    ['auth/weak-password', 'password requirements'],
    ['auth/password-does-not-meet-requirements', 'password requirements'],
    ['auth/network-request-failed', 'internet connection'],
    ['auth/too-many-requests', 'temporarily blocked'],
    ['auth/operation-not-allowed', 'disabled for this Firebase project'],
    ['auth/invalid-api-key', 'configuration does not allow'],
    ['auth/unauthorized-domain', 'configuration does not allow'],
  ])('maps %s without displaying provider message contents', (code, expected) => {
    const result = authenticationErrorMessage(
      { code, message: 'synthetic-private-email-token-password' },
      'register',
    );
    expect(result).toContain(expected);
    expect(result).not.toContain('synthetic-private');
  });
  it('keeps unknown codes diagnostic without echoing arbitrary provider messages', () => {
    expect(
      authenticationErrorMessage(
        { code: 'auth/internal-error', message: 'synthetic-secret' },
        'register',
      ),
    ).toContain('auth/internal-error');
    for (const cause of [
      new Error('synthetic-secret'),
      { code: 'auth/<synthetic-secret>' },
      null,
    ]) {
      expect(authenticationErrorMessage(cause, 'register')).not.toContain('synthetic-secret');
      expect(firebaseErrorCode(cause)).toBeNull();
    }
  });
  it('does not distinguish absent accounts and wrong passwords during login', () => {
    const messages = [
      'auth/user-not-found',
      'auth/wrong-password',
      'auth/invalid-credential',
      'auth/email-already-in-use',
    ].map((code) => authenticationErrorMessage({ code }, 'login'));
    expect(new Set(messages).size).toBe(1);
  });
  it('only allows the exact known verification guidance through', () => {
    expect(
      authenticationErrorMessage(
        new Error('Please verify your email address before signing in.'),
        'login',
      ),
    ).toContain('verify your email');
    expect(
      authenticationErrorMessage(new Error('verify your email synthetic-secret'), 'login'),
    ).not.toContain('synthetic-secret');
  });
  it('distinguishes account creation from verification, profile and signout failure', () => {
    for (const stage of ['verification', 'profile', 'cleanup'] as const) {
      const message = authenticationErrorMessage(
        new RegistrationCompletionError(stage, 'auth/network-request-failed'),
        'register',
      );
      expect(message).toContain('account was created');
      expect(message).toContain('Do not create the account again');
    }
    expect(
      authenticationErrorMessage(
        new RegistrationCompletionError('verification', 'auth/too-many-requests'),
        'register',
      ),
    ).toContain('verification email');
  });
});
