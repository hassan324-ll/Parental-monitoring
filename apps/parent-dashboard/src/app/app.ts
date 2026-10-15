import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
@Component({
  selector: 'app-root',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
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
}
