import { Component, DestroyRef, effect, inject, input, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FirebaseAuthService } from '../../core/firebase-auth.service';
import {
  SavedSharingSummary,
  SharingSummaryApiService,
} from '../../core/sharing-summary-api.service';

@Component({
  selector: 'app-sharing-summary',
  standalone: true,
  templateUrl: './sharing-summary.html',
})
export class SharingSummary {
  readonly familyId = input.required<number>();
  readonly deviceId = input.required<string>();
  readonly revoked = input(false);
  private readonly api = inject(SharingSummaryApiService);
  private readonly auth = inject(FirebaseAuthService);
  private epoch = 0;
  private readonly identity = signal<string | null>(null);
  private readonly refreshVersion = signal(0);
  readonly summary = signal<SavedSharingSummary | null>(null);
  readonly loading = signal(false);
  readonly message = signal('Verify your parent session to read saved data.');

  constructor() {
    const unsubscribe = this.auth.observeIdentity((uid) => {
      this.epoch++;
      this.summary.set(null);
      this.loading.set(false);
      this.message.set('Verify your parent session to read saved data.');
      this.identity.set(uid);
      this.refreshVersion.update((value) => value + 1);
    });
    inject(DestroyRef).onDestroy(unsubscribe);
    effect((cleanup) => {
      const family = this.familyId();
      const device = this.deviceId();
      const revoked = this.revoked();
      const uid = this.identity();
      this.refreshVersion();
      this.epoch++;
      this.summary.set(null);
      this.loading.set(false);
      if (revoked) {
        this.message.set('Device revoked. No saved sharing data is available.');
        return;
      }
      if (!uid) {
        this.message.set('Verify your parent session to read saved data.');
        return;
      }
      this.message.set('Checking saved data…');
      this.loading.set(true);
      const epoch = this.epoch;
      const current = () =>
        epoch === this.epoch &&
        uid === this.identity() &&
        family === this.familyId() &&
        device === this.deviceId() &&
        !this.revoked();
      const subscription = this.api.read(family, device).subscribe({
        next: (value) => {
          if (!current()) return;
          this.summary.set(value);
          this.loading.set(false);
          this.message.set('Saved data received. This is not a live collection status.');
        },
        error: (error: unknown) => {
          if (!current()) return;
          this.epoch++;
          this.summary.set(null);
          this.loading.set(false);
          this.message.set(
            error instanceof HttpErrorResponse && error.status === 403
              ? 'Only the family owner or a parent can read saved data.'
              : error instanceof HttpErrorResponse && error.status === 401
                ? 'Session expired. Sign in again before reading saved data.'
                : 'Saved data is unverified. Refresh to retry; previous data has been hidden.',
          );
        },
      });
      cleanup(() => subscription.unsubscribe());
    });
  }
  refresh(): void {
    this.epoch++;
    this.summary.set(null);
    this.refreshVersion.update((value) => value + 1);
  }
}
