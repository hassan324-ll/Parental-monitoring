import { Injectable, InjectionToken, inject } from '@angular/core';
import { FirebaseApp, FirebaseOptions, getApps, initializeApp } from 'firebase/app';
import {
  Auth,
  User,
  createUserWithEmailAndPassword,
  getAuth,
  onAuthStateChanged,
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
    onAuthStateChanged,
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
    const credential = await this.sdk.signInWithEmailAndPassword(auth, email.trim(), password);
    try {
      await this.sdk.reload(credential.user);
      if (!credential.user.emailVerified) {
        throw new Error('Please verify your email address before signing in.');
      }
    } catch (error) {
      await this.sdk.signOut(auth);
      throw error;
    }
  }

  async resetPassword(email: string): Promise<void> {
    await this.sdk.sendPasswordResetEmail(this.requireAuth(), email.trim());
  }

  observeIdentity(listener: (uid: string | null) => void): () => void {
    if (!this.auth) {
      listener(null);
      return () => {};
    }
    return this.sdk.onAuthStateChanged(
      this.auth,
      (user) => listener(user?.emailVerified ? user.uid : null),
      () => listener(null),
    );
  }
  async logout(): Promise<void> {
    if (this.auth) await this.sdk.signOut(this.auth);
  }

  async verifiedUser(): Promise<User | null> {
    if (!this.auth) return null;
    await this.auth.authStateReady();
    const user = this.auth.currentUser;
    if (!user) return null;
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
