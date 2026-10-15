import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { HealthService } from '../core/health.service';
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
      </div>
    }
    <article class="empty">
      <h2>
        {{
          title === 'Dashboard' ? 'Your family workspace starts here' : title + ' is being prepared'
        }}
      </h2>
      <p class="muted">
        No family data is connected. This phase provides the application foundation.
      </p>
      <p class="muted">Authentication, pairing and reporting are planned features.</p>
    </article>
  </section>`,
})
export class FeaturePage {
  title = inject(ActivatedRoute).snapshot.data['title'] as string;
  private api = inject(HealthService);
  health = signal('Not checked');
  check() {
    this.health.set('Checking…');
    this.api.check().subscribe({
      next: () => this.health.set('API available'),
      error: () => this.health.set('API unavailable — start Laravel on port 8000'),
    });
  }
}
