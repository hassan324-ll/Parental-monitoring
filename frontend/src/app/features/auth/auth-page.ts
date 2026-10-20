import { Component, inject, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { FirebaseAuthService } from '../../core/firebase-auth.service';
import { authenticationErrorMessage } from '../../core/authentication-errors';

type AuthMode = 'login' | 'register' | 'reset';

@Component({
  standalone: true,
  selector: 'app-auth-page',
  imports: [ReactiveFormsModule],
  templateUrl: './auth-page.html',
  styleUrl: './auth-page.scss',
})
export class AuthPage {
  readonly auth = inject(FirebaseAuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  readonly mode = signal<AuthMode>('login');
  readonly busy = signal(false);
  readonly message = signal('');
  readonly error = signal('');

  readonly form = new FormGroup({
    name: new FormControl('', { nonNullable: true }),
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
    password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required],
    }),
  });

  setMode(mode: AuthMode): void {
    this.mode.set(mode);
    this.form.controls.password.reset();
    this.error.set('');
    this.message.set('');
    this.updateValidators();
  }

  private updateValidators(): void {
    const name = this.form.controls.name;
    name.setValidators(
      this.mode() === 'register'
        ? [Validators.required, Validators.pattern(/\S/), Validators.maxLength(100)]
        : [],
    );
    name.updateValueAndValidity();

    const password = this.form.controls.password;
    password.setValidators(
      this.mode() === 'reset'
        ? []
        : this.mode() === 'register'
          ? [Validators.required, Validators.minLength(8)]
          : [Validators.required],
    );
    password.updateValueAndValidity();
  }

  async submit(): Promise<void> {
    this.updateValidators();
    if (!this.auth.configured || this.form.invalid || this.busy()) return;

    this.busy.set(true);
    this.error.set('');
    this.message.set('');
    const { name, email, password } = this.form.getRawValue();

    try {
      if (this.mode() === 'register') {
        await this.auth.register(name, email, password);
        this.setMode('login');
        this.message.set('Check your email to verify your account before signing in.');
      } else if (this.mode() === 'reset') {
        // Generic response avoids exposing whether this email has an account.
        try {
          await this.auth.resetPassword(email);
        } catch {
          // Intentional account-enumeration protection.
        }
        this.message.set('If an account exists, reset instructions have been sent.');
      } else {
        await this.auth.login(email, password);
        const next = this.route.snapshot.queryParamMap.get('next') ?? '/dashboard';
        const localPath = next.startsWith('/') && !next.startsWith('//') ? next : '/dashboard';
        await this.router.navigateByUrl(localPath);
      }
    } catch (cause: unknown) {
      this.error.set(authenticationErrorMessage(cause, this.mode()));
    } finally {
      this.busy.set(false);
    }
  }
}
