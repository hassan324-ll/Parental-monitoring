import { Injectable } from '@angular/core';
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

@Injectable({ providedIn: 'root' })
export class FirebaseAuthService {
  private readonly auth: Auth | null;

  constructor() {
    const options: FirebaseOptions | null = environment.firebaseConfig;
    if (!options) {
      this.auth = null;
      return;
    }
    try {
      const name = 'familyguard-parent';
      const app: FirebaseApp =
        getApps().find((candidate) => candidate.name === name) ?? initializeApp(options, name);
      this.auth = getAuth(app);
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
    const credential = await createUserWithEmailAndPassword(auth, email.trim(), password);
    try {
      await updateProfile(credential.user, { displayName: name.trim() });
      await sendEmailVerification(credential.user);
    } finally {
      await signOut(auth);
    }
  }

  async login(email: string, password: string): Promise<void> {
    const auth = this.requireAuth();
    const credential = await signInWithEmailAndPassword(auth, email.trim(), password);
    try {
      await reload(credential.user);
      if (!credential.user.emailVerified) {
        throw new Error('Please verify your email address before signing in.');
      }
    } catch (error) {
      await signOut(auth);
      throw error;
    }
  }

  async resetPassword(email: string): Promise<void> {
    await sendPasswordResetEmail(this.requireAuth(), email.trim());
  }

  async logout(): Promise<void> {
    if (this.auth) await signOut(this.auth);
  }

  async verifiedUser(): Promise<User | null> {
    if (!this.auth) return null;
    await this.auth.authStateReady();
    const user = this.auth.currentUser;
    if (!user) return null;
    const alreadyVerified = user.emailVerified;
    try {
      await reload(user);
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
