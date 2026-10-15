# Firebase preparation and parent authentication

No live Firebase project, cloud credentials, billing or account login has been configured.

## Angular parent authentication

1. Create/select a Firebase Console project, enable Authentication > Sign-in method > Email/Password.
2. Register a Web app. Copy its public configuration (apiKey, authDomain, projectId, appId and optional messagingSenderId).
3. Configure the Firebase web options as environment.firebaseConfig in the local Angular configuration. Leave it null until real configuration is available. Never put a service account JSON or a private key in frontend code.
4. Review provider authorized domains for the development and production hosts.
5. Test account registration, email verification, sign in, password reset and logout with test identities.

With firebaseConfig null, the Angular parent login page explicitly disables account actions. Protected Angular routes redirect to /auth. The code includes a token interceptor that attaches a verified email account's Firebase ID token only to the configured API prefix. This is only a client convenience; it is not sufficient to authorize any data.

## Laravel: blocked until server verifier is implemented

The protected family APIs use Laravel auth middleware and deny anonymous HTTP callers. A future production guard must validate Firebase ID token signature, issuer, audience, expiry, and ideally revocation using a maintained Firebase Admin SDK. The verified provider UID must map to an authorized local Laravel user. Do not add an authentication bypass. Until the server verifier exists, successful Firebase browser sign-in does NOT make the family APIs usable.

## Android companion

Register Android package com.familyguard.child and its debug suffix where appropriate. Android google-services.json must remain ignored and private. Pairing with short-lived device codes and revocable device-scoped credentials will be implemented in a separate phase with visible device-side approval.

## Testing

Angular build/unit tests and Firebase live-provider tests must be executed and recorded. Laravel authorization tests must pass with forged bearer tokens rejected and cross-family isolation enforced. Do not claim a feature passed unless tested.
