export class RegistrationCompletionError extends Error {
  constructor(
    readonly stage: 'profile' | 'verification' | 'cleanup',
    readonly providerCode: string | null,
  ) {
    super('Account created; registration follow-up failed.');
    this.name = 'RegistrationCompletionError';
  }
}

export function firebaseErrorCode(cause: unknown): string | null {
  if (!cause || typeof cause !== 'object' || !('code' in cause)) return null;
  const code = cause.code;
  return typeof code === 'string' && /^auth\/[a-z0-9-]{1,80}$/.test(code) ? code : null;
}

/** Never display raw provider messages: they can contain email addresses/request details. */
export function authenticationErrorMessage(
  cause: unknown,
  mode: 'login' | 'register' | 'reset',
): string {
  if (cause instanceof RegistrationCompletionError) {
    const suffix = cause.providerCode ? ` (${cause.providerCode})` : '';
    if (cause.stage === 'cleanup')
      return (
        'Your account was created, but the session could not be closed. Do not create the account again. Use Sign out before trying to sign in.' +
        suffix
      );
    return cause.stage === 'verification'
      ? 'Your account was created, but the verification email could not be confirmed. Do not create the account again. Check your inbox and spam folder; if no email arrived, contact the project owner.' +
          suffix
      : 'Your account was created, but profile setup did not finish. Do not create the account again. Contact the project owner to complete setup.' +
          suffix;
  }
  if (
    cause instanceof Error &&
    cause.message === 'Please verify your email address before signing in.'
  )
    return cause.message;
  const code = firebaseErrorCode(cause);
  switch (code) {
    case 'auth/email-already-in-use':
      return mode === 'register'
        ? 'An account already uses this email address. Sign in or use Forgot password.'
        : 'Unable to sign in. Check your email and password.';
    case 'auth/invalid-email':
      return 'Enter a valid email address.';
    case 'auth/weak-password':
    case 'auth/password-does-not-meet-requirements':
      return 'Your password does not meet the Firebase password requirements. Choose a stronger password.';
    case 'auth/network-request-failed':
      return 'Cannot connect to Firebase. Check your internet connection and try again.';
    case 'auth/too-many-requests':
      return 'Firebase temporarily blocked this request. Wait before trying again. (auth/too-many-requests)';
    case 'auth/operation-not-allowed':
      return 'Email/password authentication is disabled for this Firebase project. Ask the project owner to enable it. (auth/operation-not-allowed)';
    case 'auth/invalid-api-key':
    case 'auth/app-not-authorized':
    case 'auth/unauthorized-domain':
      return (
        'Firebase configuration does not allow this app. Contact the project owner. (' + code + ')'
      );
    case 'auth/user-not-found':
    case 'auth/wrong-password':
    case 'auth/invalid-credential':
      return 'Unable to sign in. Check your email and password.';
    default:
      return code
        ? 'Firebase could not complete this request. Try again or contact the project owner. (' +
            code +
            ')'
        : 'Unable to complete the request. Check your details and try again.';
  }
}
