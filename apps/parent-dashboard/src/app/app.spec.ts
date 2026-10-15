import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { App } from './app';
describe('FamilyGuard shell', () => {
  it('offers accessible navigation for all foundation pages', async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter([])],
    }).compileComponents();
    const f = TestBed.createComponent(App);
    f.detectChanges();
    expect(f.nativeElement.querySelectorAll('nav a').length).toBe(11);
    expect(f.nativeElement.textContent).toContain('FamilyGuard');
  });
});
