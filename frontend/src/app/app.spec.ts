import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { FirebaseAuthService } from './core/firebase-auth.service';
import { App } from './app';

describe('FamilyGuard shell', () => {
  it('offers accessible navigation for all foundation pages', async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideRouter([]),
        { provide: FirebaseAuthService, useValue: { configured: false, logout: vi.fn() } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelectorAll('nav a').length).toBe(11);
    expect(fixture.nativeElement.textContent).toContain('FamilyGuard');
  });
});
