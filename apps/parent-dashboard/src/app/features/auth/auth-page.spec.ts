import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthPage } from './auth-page';

describe('FamilyGuard parent authentication', () => {
  it('fails closed until the Firebase web project is configured', async () => {
    await TestBed.configureTestingModule({
      imports: [AuthPage],
      providers: [provideRouter([])],
    }).compileComponents();

    const fixture = TestBed.createComponent(AuthPage);
    fixture.detectChanges();

    expect(fixture.componentInstance.auth.configured).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('Authentication is disabled');
    expect(fixture.nativeElement.querySelector('form')).toBeNull();
  });
});
