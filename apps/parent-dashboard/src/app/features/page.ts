import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { HealthService } from '../core/health.service';
import { ParentProfileService } from '../core/parent-profile.service';

@Component({
  standalone: true,
  imports: [MatButtonModule],
  template: `<section class="page">
    <span class="eyebrow">FAMILYGUARD</span>
    <h1>{{ title }}</h1>
    <p class="muted">A clear view of your family’s safety, built on permission and trust.</p>
    @if (title === 'Dashboard') {
      <div class="cards">
        <article class="card">
          <p class="muted">Connected devices</p>
          <div class="metric">0</div>
          <p class="muted">No devices paired</p>
        </article>
        <article class="card">
          <p class="muted">Sharing sessions</p>
          <div class="metric">0</div>
          <p class="muted">No active sessions</p>
        </article>
        <article class="card">
          <p class="muted">API connection</p>
          <div class="status" role="status">{{ health() }}</div>
          <button matButton (click)="check()">Check connection</button>
        </article>
        <article class="card">
          <p class="muted">Parent identity</p>
          <div class="status" role="status">{{ parentStatus() }}</div>
          <p class="muted">{{ parentName() }}</p>
          <button matButton (click)="checkParent()">Verify with Laravel</button>
        </article>
      </div>
    }
    <article class="empty">
      <h2>
        {{
          title === 'Dashboard' ? 'Your family workspace starts here' : title + ' is being prepared'
        }}
      </h2>
      <p class="muted">
        No child devices are connected. Device pairing and monitoring remain separate upcoming
        phases.
      </p>
      <p class="muted">
        A successful Firebase sign-in is not the same as Laravel authorization. Verify your parent
        identity with the secure API above.
      </p>
    </article>
  </section>`,
})
export class FeaturePage {
  title = inject(ActivatedRoute).snapshot.data['title'] as string;
  private readonly healthApi = inject(HealthService);
  private readonly profileApi = inject(ParentProfileService);

  health = signal('Not checked');
  parentStatus = signal('Not checked');
  parentName = signal('');

  check() {
    this.health.set('Checking…');
    this.healthApi.check().subscribe({
      next: () => this.health.set('API available'),
      error: () => this.health.set('API unavailable — start Laravel on port 8000'),
    });
  }

  checkParent() {
    this.parentStatus.set('Checking…');
    this.parentName.set('');
    this.profileApi.load().subscribe({
      next: (response) => {
        this.parentStatus.set('Identity verified by Laravel');
        this.parentName.set(response.data.name);
      },
      error: () =>
        this.parentStatus.set('Unable to verify — check Firebase project ID and Laravel API'),
    });
  }
}
