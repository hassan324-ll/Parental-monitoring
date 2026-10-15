import { Component, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { FirebaseAuthService } from './core/firebase-auth.service';

@Component({
  selector: 'app-root',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  readonly auth = inject(FirebaseAuthService);
  private readonly router = inject(Router);

  links = [
    ['auth', 'Authentication'],
    ['dashboard', 'Dashboard'],
    ['children', 'My Children'],
    ['devices', 'Connected Devices'],
    ['activity', 'Application Activity'],
    ['screen-time', 'Screen Time'],
    ['location', 'GPS Location'],
    ['screen-sharing', 'Screen Sharing'],
    ['alerts', 'Alerts'],
    ['reports', 'Reports'],
    ['settings', 'Settings'],
  ];

  async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/auth');
  }
}
