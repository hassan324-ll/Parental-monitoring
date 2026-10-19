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
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
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
  it('expires a saved snapshot after one minute without polling or accepting a late response', () => {
    vi.useFakeTimers();
    const f = setup();
    responses[0].next(data());
    vi.advanceTimersByTime(59999);
    expect(f.componentInstance.summary()).not.toBeNull();
    vi.advanceTimersByTime(1);
    expect(f.componentInstance.summary()).toBeNull();
    responses[0].next(data());
    expect(f.componentInstance.summary()).toBeNull();
    expect(f.componentInstance.message()).toContain('expired');
    expect(api.read).toHaveBeenCalledTimes(1);
    f.componentInstance.refresh();
    f.detectChanges();
    responses[1].next(data());
    expect(f.componentInstance.summary()).not.toBeNull();
    f.destroy();
  });
  it('hides synchronously on tab hide, cancels in-flight reads and requires refresh on return', () => {
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    const f = setup();
    responses[0].next(data());
    visibility.mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(f.componentInstance.summary()).toBeNull();
    responses[0].next(data());
    expect(f.componentInstance.summary()).toBeNull();
    f.detectChanges();
    expect(responses[0].observed).toBe(false);
    visibility.mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    f.detectChanges();
    expect(api.read).toHaveBeenCalledTimes(1);
    f.componentInstance.refresh();
    f.detectChanges();
    expect(api.read).toHaveBeenCalledTimes(2);
    f.destroy();
  });
  it('stops on offline and does not silently fetch when online returns', () => {
    const online = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
    const f = setup();
    responses[0].next(data());
    online.mockReturnValue(false);
    window.dispatchEvent(new Event('offline'));
    expect(f.componentInstance.summary()).toBeNull();
    f.detectChanges();
    f.componentInstance.refresh();
    f.detectChanges();
    expect(api.read).toHaveBeenCalledTimes(1);
    online.mockReturnValue(true);
    window.dispatchEvent(new Event('online'));
    f.detectChanges();
    expect(api.read).toHaveBeenCalledTimes(1);
    f.componentInstance.refresh();
    f.detectChanges();
    expect(api.read).toHaveBeenCalledTimes(2);
    f.destroy();
  });
  it('does not request data when first opened in a hidden tab', () => {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    const f = setup();
    expect(api.read).not.toHaveBeenCalled();
    f.componentInstance.refresh();
    expect(api.read).not.toHaveBeenCalled();
    f.destroy();
  });
  it('releases privacy event listeners and expiry timer on destruction', () => {
    vi.useFakeTimers();
    const f = setup();
    responses[0].next(data());
    const documentRemove = vi.spyOn(document, 'removeEventListener');
    const windowRemove = vi.spyOn(window, 'removeEventListener');
    f.destroy();
    const message = f.componentInstance.message();
    vi.advanceTimersByTime(60001);
    window.dispatchEvent(new Event('offline'));
    expect(f.componentInstance.message()).toBe(message);
    expect(documentRemove).toHaveBeenCalledWith('visibilitychange', expect.any(Function));
    expect(windowRemove).toHaveBeenCalledWith('offline', expect.any(Function));
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
