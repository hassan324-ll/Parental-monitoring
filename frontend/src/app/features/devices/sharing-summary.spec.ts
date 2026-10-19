import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject } from 'rxjs';
import { FirebaseAuthService } from '../../core/firebase-auth.service';
import {
  SavedSharingSummary,
  SharingSummaryApiService,
} from '../../core/sharing-summary-api.service';
import { SharingSummary } from './sharing-summary';
const id = '11111111-1111-4111-8111-111111111111';
const other = '22222222-2222-4222-8222-222222222222';
const data = (device_id = id): SavedSharingSummary => ({
  device_id,
  revoked: false,
  collection_available: false,
  usage: {
    consent_current: true,
    retention: '7 UTC calendar days including today',
    days: [
      {
        day: '2026-10-19',
        recorded_until: '2026-10-19T11:00:00Z',
        total_seconds: 60,
        apps: [{ package_name: 'example.synthetic', duration_seconds: 60 }],
      },
    ],
  },
  location: { consent_current: false, retention: '24 hours', latest: null },
});
describe('saved summary identity and request lifecycle', () => {
  let identity: (uid: string | null) => void;
  let release: ReturnType<typeof vi.fn>;
  let api: { read: ReturnType<typeof vi.fn> };
  let responses: Subject<SavedSharingSummary>[];
  beforeEach(() => {
    release = vi.fn();
    responses = [];
    api = {
      read: vi.fn().mockImplementation(() => {
        const response = new Subject<SavedSharingSummary>();
        responses.push(response);
        return response;
      }),
    };
    TestBed.configureTestingModule({
      imports: [SharingSummary],
      providers: [
        { provide: SharingSummaryApiService, useValue: api },
        {
          provide: FirebaseAuthService,
          useValue: {
            observeIdentity: (listener: typeof identity) => {
              identity = listener;
              listener('synthetic-parent');
              return release;
            },
          },
        },
      ],
    });
  });
  function setup() {
    const fixture = TestBed.createComponent(SharingSummary);
    fixture.componentRef.setInput('familyId', 1);
    fixture.componentRef.setInput('deviceId', id);
    fixture.detectChanges();
    return fixture;
  }
  it('renders saved data with unavailable collection and no activation controls', () => {
    const f = setup();
    responses[0].next(data());
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('example.synthetic');
    expect(f.nativeElement.textContent).toContain('Collection is unavailable');
    expect(f.nativeElement.querySelectorAll('input').length).toBe(0);
  });
  it('cancels old device/family requests and ignores late responses', () => {
    const f = setup();
    f.componentRef.setInput('deviceId', other);
    f.componentRef.setInput('familyId', 2);
    f.detectChanges();
    responses[0].next(data());
    expect(f.componentInstance.summary()).toBeNull();
    responses[1].next(data(other));
    expect(f.componentInstance.summary()?.device_id).toBe(other);
    expect(responses[0].observed).toBe(false);
  });
  it('clears synchronously on signout and rejects a response before effect cleanup', () => {
    const f = setup();
    responses[0].next(data());
    identity(null);
    responses[0].next(data());
    expect(f.componentInstance.summary()).toBeNull();
    f.detectChanges();
    expect(responses[0].observed).toBe(false);
    expect(api.read).toHaveBeenCalledTimes(1);
  });
  it('clears on account change and only accepts the new request', () => {
    const f = setup();
    responses[0].next(data());
    identity('other-parent');
    expect(f.componentInstance.summary()).toBeNull();
    responses[0].next(data());
    expect(f.componentInstance.summary()).toBeNull();
    f.detectChanges();
    responses[1].next(data());
    expect(f.componentInstance.summary()).not.toBeNull();
  });
  it('hides data on refresh failure and provides truthful permission errors', () => {
    const f = setup();
    responses[0].next(data());
    f.componentInstance.refresh();
    expect(f.componentInstance.summary()).toBeNull();
    f.detectChanges();
    responses[1].error(new HttpErrorResponse({ status: 403 }));
    f.detectChanges();
    expect(f.nativeElement.textContent).not.toContain('example.synthetic');
    expect(f.nativeElement.textContent).toContain('Only the family owner');
    f.componentInstance.refresh();
    f.detectChanges();
    responses[2].error(new HttpErrorResponse({ status: 0 }));
    expect(f.componentInstance.message()).toContain('unverified');
  });
  it('hides on revocation and cancels pending requests on destruction', () => {
    const f = setup();
    responses[0].next(data());
    f.componentRef.setInput('revoked', true);
    f.detectChanges();
    expect(f.componentInstance.summary()).toBeNull();
    expect(api.read).toHaveBeenCalledTimes(1);
    f.destroy();
    expect(release).toHaveBeenCalledOnce();
    expect(responses[0].observed).toBe(false);
  });
  it('does not request saved data without a verified identity', () => {
    const f = TestBed.createComponent(SharingSummary);
    identity(null);
    f.componentRef.setInput('familyId', 1);
    f.componentRef.setInput('deviceId', id);
    f.detectChanges();
    expect(api.read).not.toHaveBeenCalled();
  });
});
