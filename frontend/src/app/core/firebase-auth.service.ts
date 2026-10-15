import { Injectable, InjectionToken, inject } from '@angular/core';
import { FirebaseApp, FirebaseOptions, getApps, initializeApp } from 'firebase/app';
import {
  Auth,
  User,
  createUserWithEmailAndPassword,
  getAuth,
  reload,
  sendEmailVerification,
  sendPasswordResetEmail,
  signInWithEmailAndPassword,
  signOut,
  updateProfile,
} from 'firebase/auth';
import { environment } from '../../environments/environment';

// Inject the SDK boundary so unit tests cannot accidentally call the real provider.
export const FIREBASE_CLIENT = new InjectionToken('FamilyGuard Firebase client', {
  providedIn: 'root',
  factory: () => ({
    initializeApp,
    getApps,
    getAuth,
    reload,
    signOut,
    signInWithEmailAndPassword,
    createUserWithEmailAndPassword,
    sendEmailVerification,
    sendPasswordResetEmail,
    updateProfile,
  }),
});

@Injectable({ providedIn: 'root' })
export class FirebaseAuthService {
  private readonly auth: Auth | null;
  private readonly sdk = inject(FIREBASE_CLIENT);
  private freshSignIn: { user: User; checkedAt: number } | null = null;

  constructor() {
    const options: FirebaseOptions | null = environment.firebaseConfig;
    if (!options) {
      this.auth = null;
      return;
    }
    try {
      const name = 'familyguard-parent';
      const app: FirebaseApp =
        this.sdk.getApps().find((candidate) => candidate.name === name) ??
        this.sdk.initializeApp(options, name);
      this.auth = this.sdk.getAuth(app);
    } catch {
      this.auth = null;
    }
  }

  get configured(): boolean {
    return this.auth !== null;
  }

  private requireAuth(): Auth {
    if (!this.auth) throw new Error('Firebase is not configured. See docs/firebase-setup.md.');
    return this.auth;
  }

  async register(name: string, email: string, password: string): Promise<void> {
    const auth = this.requireAuth();
    this.freshSignIn = null;
    const credential = await this.sdk.createUserWithEmailAndPassword(auth, email.trim(), password);
    try {
      await this.sdk.updateProfile(credential.user, { displayName: name.trim() });
      await this.sdk.sendEmailVerification(credential.user);
    } finally {
      await this.sdk.signOut(auth);
    }
  }

  async login(email: string, password: string): Promise<void> {
    const auth = this.requireAuth();
    this.freshSignIn = null;
    const credential = await this.sdk.signInWithEmailAndPassword(auth, email.trim(), password);
    try {
      // Firebase sign-in already loads account info before returning UserCredential.
      // Avoid a duplicate lookup; keep the verified-email and current-session checks.
      if (auth.currentUser !== credential.user) {
        throw new Error('The authentication session changed. Please sign in again.');
      }
      if (!credential.user.emailVerified) {
        throw new Error('Please verify your email address before signing in.');
      }
      this.freshSignIn = { user: credential.user, checkedAt: Date.now() };
    } catch (error) {
      await this.sdk.signOut(auth);
      throw error;
    }
  }

  async resetPassword(email: string): Promise<void> {
    await this.sdk.sendPasswordResetEmail(this.requireAuth(), email.trim());
  }

  async logout(): Promise<void> {
    this.freshSignIn = null;
    if (this.auth) await this.sdk.signOut(this.auth);
  }

  async verifiedUser(): Promise<User | null> {
    if (!this.auth) return null;
    await this.auth.authStateReady();
    const user = this.auth.currentUser;
    const fresh = this.freshSignIn;
    this.freshSignIn = null;
    if (!user) return null;
    // One-use handoff to the immediate post-login guard, never a persisted cache.
    // Later navigation/restored sessions still reload and fail closed on errors.
    const age = fresh ? Date.now() - fresh.checkedAt : -1;
    if (fresh?.user === user && user.emailVerified && age >= 0 && age < 5_000) return user;
    const alreadyVerified = user.emailVerified;
    try {
      await this.sdk.reload(user);
      if (this.auth.currentUser !== user || !user.emailVerified) return null;
      if (!alreadyVerified) await user.getIdToken(true);
      return this.auth.currentUser === user ? user : null;
    } catch {
      return null;
    }
  }

  async idToken(): Promise<string | null> {
    if (!this.auth) return null;
    await this.auth.authStateReady();
    const user = this.auth.currentUser;
    if (!user || !user.emailVerified) return null;
    return user.getIdToken();
  }
}
