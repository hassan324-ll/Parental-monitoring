import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { FirebaseAuthService } from '../../core/firebase-auth.service';
import { AuthPage } from './auth-page';

describe('FamilyGuard parent authentication', () => {
  it('fails closed when Firebase configuration is unavailable', async () => {
    await TestBed.configureTestingModule({
      imports: [AuthPage],
      providers: [
        provideRouter([]),
        { provide: FirebaseAuthService, useValue: { configured: false } },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(AuthPage);
    fixture.detectChanges();

    expect(fixture.componentInstance.auth.configured).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('Authentication is disabled');
    expect(fixture.nativeElement.querySelector('form')).toBeNull();
  });
});
