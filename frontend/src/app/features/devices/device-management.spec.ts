import { TestBed } from '@angular/core/testing';
import { of, Subject } from 'rxjs';
import { FamilyApiService } from '../../core/family-api.service';
import { DevicePairingApiService, PairingInvitation } from '../../core/device-pairing-api.service';
import { DeviceManagement } from './device-management';

describe('Connected Devices contracts and state', () => {
  const page = (data: unknown[], current_page = 1, last_page = 2) => ({
    data,
    meta: { current_page, last_page, total: 21 },
  });
  let families: { listFamilies: ReturnType<typeof vi.fn>; listChildren: ReturnType<typeof vi.fn> };
  let api: {
    listDevices: ReturnType<typeof vi.fn>;
    createInvitation: ReturnType<typeof vi.fn>;
    revokeDevice: ReturnType<typeof vi.fn>;
  };
  beforeEach(() => {
    families = {
      listFamilies: vi.fn().mockReturnValue(
        of(
          page([
            { id: 1, name: 'Synthetic family', owner_id: 1 },
            { id: 2, name: 'Other family', owner_id: 1 },
          ]),
        ),
      ),
      listChildren: vi
        .fn()
        .mockReturnValue(of(page([{ id: 3, family_id: 1, display_name: 'Child' }]))),
    };
    api = {
      listDevices: vi.fn().mockReturnValue(of(page([]))),
      createInvitation: vi.fn(),
      revokeDevice: vi.fn().mockReturnValue(of({ message: 'Device access revoked.' })),
    };
    TestBed.configureTestingModule({
      imports: [DeviceManagement],
      providers: [
        { provide: FamilyApiService, useValue: families },
        { provide: DevicePairingApiService, useValue: api },
      ],
    });
  });
  async function setup() {
    const fixture = TestBed.createComponent(DeviceManagement);
    fixture.detectChanges();
    await vi.waitFor(() => expect(fixture.componentInstance.loading()).toBe(false));
    fixture.detectChanges();
    return fixture;
  }
  it('keeps loading active when an older family page finishes during a newer selection', async () => {
    const f = await setup();
    const olderChildren = new Subject<ReturnType<typeof page>>();
    const newerChildren = new Subject<ReturnType<typeof page>>();
    families.listChildren.mockReturnValueOnce(olderChildren).mockReturnValueOnce(newerChildren);
    const olderPage = f.componentInstance.loadFamilies();
    await vi.waitFor(() => expect(families.listChildren).toHaveBeenCalledTimes(2));
    const newerSelection = f.componentInstance.selectFamily(2);
    olderChildren.next(page([{ id: 3, family_id: 1, display_name: 'Old child' }]));
    await olderPage;
    expect(f.componentInstance.loading()).toBe(true);
    newerChildren.next(page([{ id: 4, family_id: 2, display_name: 'Current child' }]));
    await newerSelection;
    expect(f.componentInstance.loading()).toBe(false);
    expect(f.componentInstance.children()[0].display_name).toBe('Current child');
  });

  it('keeps the newest family page when responses arrive out of order', async () => {
    const f = await setup();
    const older = new Subject<ReturnType<typeof page>>();
    const newer = new Subject<ReturnType<typeof page>>();
    families.listFamilies.mockReturnValueOnce(older).mockReturnValueOnce(newer);
    const olderPage = f.componentInstance.loadFamilies(1);
    const newerPage = f.componentInstance.loadFamilies(2);
    newer.next(page([{ id: 2, name: 'Current family', owner_id: 1 }], 2));
    await newerPage;
    older.next(page([{ id: 1, name: 'Old family', owner_id: 1 }], 1));
    await olderPage;
    expect(f.componentInstance.familyPage()).toBe(2);
    expect(f.componentInstance.selectedFamilyId()).toBe(2);
    expect(f.componentInstance.families()[0].name).toBe('Current family');
  });

  it('does not start child or device requests after the component is destroyed', async () => {
    const f = await setup();
    const response = new Subject<ReturnType<typeof page>>();
    families.listFamilies.mockReturnValueOnce(response);
    const pending = f.componentInstance.loadFamilies(2);
    const childCalls = families.listChildren.mock.calls.length;
    const deviceCalls = api.listDevices.mock.calls.length;
    f.destroy();
    response.next(page([{ id: 2, name: 'Late family', owner_id: 1 }], 2));
    await pending;
    expect(families.listChildren).toHaveBeenCalledTimes(childCalls);
    expect(api.listDevices).toHaveBeenCalledTimes(deviceCalls);
  });
  it('clears the selected family and loading state for an empty current page', async () => {
    const f = await setup();
    families.listFamilies.mockReturnValueOnce(of(page([], 1, 1)));
    await f.componentInstance.loadFamilies();
    expect(f.componentInstance.selectedFamilyId()).toBeNull();
    expect(f.componentInstance.children()).toEqual([]);
    expect(f.componentInstance.devices()).toEqual([]);
    expect(f.componentInstance.loading()).toBe(false);
  });

  it('still reports a current family-page failure and clears loading', async () => {
    const f = await setup();
    const response = new Subject<ReturnType<typeof page>>();
    families.listFamilies.mockReturnValueOnce(response);
    const pending = f.componentInstance.loadFamilies(2);
    response.error(new Error('Current page unavailable'));
    await pending;
    expect(f.componentInstance.error()).toBe('Request failed. Please try again.');
    expect(f.componentInstance.loading()).toBe(false);
  });
  it('sends pagination parameters and renders all three pagination controls', async () => {
    const f = await setup();
    await f.componentInstance.loadFamilies(2);
    expect(families.listFamilies).toHaveBeenLastCalledWith(2);
    await f.componentInstance.selectFamily(1, 2, 2);
    expect(families.listChildren).toHaveBeenLastCalledWith(1, 2);
    expect(api.listDevices).toHaveBeenLastCalledWith(1, 2);
    f.detectChanges();
    expect(f.nativeElement.querySelectorAll('nav').length).toBe(3);
  });
  it('discards an invitation response after switching away and back to the same family', async () => {
    const f = await setup();
    const response = new Subject<PairingInvitation>();
    api.createInvitation.mockReturnValue(response);
    const pending = f.componentInstance.createInvitation(3);
    await f.componentInstance.selectFamily(2);
    await f.componentInstance.selectFamily(1);
    response.next({
      code: 'A'.repeat(32),
      expires_at: new Date(Date.now() + 600000).toISOString(),
      child: { id: 3, display_name: 'Child' },
    });
    await pending;
    expect(f.componentInstance.invitation()).toBeNull();
  });
  it('does not display an expired code and keeps its expiry explanation', async () => {
    const f = await setup();
    f.componentInstance.invitation.set({
      code: 'B'.repeat(32),
      expires_at: '2000-01-01T00:00:00Z',
      child: { id: 3, display_name: 'Child' },
    });
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('This invitation has expired');
    expect(f.nativeElement.textContent).not.toContain('B'.repeat(32));
  });
  it('ignores a revocation failure after switching to another family', async () => {
    const f = await setup();
    const response = new Subject<{ message: string }>();
    api.revokeDevice.mockReturnValue(response);
    const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(true);
    try {
      const pending = f.componentInstance.revoke({
        id: 4,
        public_id: 'synthetic-id',
        name: 'Synthetic phone',
        android_version: null,
        last_seen_at: null,
        revoked_at: null,
      });
      await f.componentInstance.selectFamily(2);
      response.error(new Error('Old family request failed'));
      await pending;
      expect(f.componentInstance.selectedFamilyId()).toBe(2);
      expect(f.componentInstance.error()).toBe('');
      expect(f.componentInstance.busy()).toBe(false);
    } finally {
      confirm.mockRestore();
    }
  });
  it('still displays a revocation failure for the current family', async () => {
    const f = await setup();
    const response = new Subject<{ message: string }>();
    api.revokeDevice.mockReturnValue(response);
    const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(true);
    try {
      const pending = f.componentInstance.revoke({
        id: 4,
        public_id: 'synthetic-id',
        name: 'Synthetic phone',
        android_version: null,
        last_seen_at: null,
        revoked_at: null,
      });
      response.error(new Error('Current family request failed'));
      await pending;
      expect(f.componentInstance.error()).toBe('Request failed. Please try again.');
      expect(f.componentInstance.busy()).toBe(false);
    } finally {
      confirm.mockRestore();
    }
  });
  it('does not display a stale revocation notice after switching during its refresh', async () => {
    const f = await setup();
    const refresh = new Subject<ReturnType<typeof page>>();
    families.listChildren.mockReturnValueOnce(refresh);
    const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(true);
    try {
      const pending = f.componentInstance.revoke({
        id: 4,
        public_id: 'synthetic-id',
        name: 'Synthetic phone',
        android_version: null,
        last_seen_at: null,
        revoked_at: null,
      });
      await vi.waitFor(() => expect(f.componentInstance.loading()).toBe(true));
      await f.componentInstance.selectFamily(2);
      refresh.next(page([]));
      await pending;
      expect(f.componentInstance.selectedFamilyId()).toBe(2);
      expect(f.componentInstance.notice()).toBe('');
      expect(f.componentInstance.busy()).toBe(false);
    } finally {
      confirm.mockRestore();
    }
  });

  it('does not display a revocation notice after destruction during its refresh', async () => {
    const f = await setup();
    const refresh = new Subject<ReturnType<typeof page>>();
    families.listChildren.mockReturnValueOnce(refresh);
    const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(true);
    try {
      const pending = f.componentInstance.revoke({
        id: 4,
        public_id: 'synthetic-id',
        name: 'Synthetic phone',
        android_version: null,
        last_seen_at: null,
        revoked_at: null,
      });
      await vi.waitFor(() => expect(f.componentInstance.loading()).toBe(true));
      f.destroy();
      refresh.next(page([]));
      await pending;
      expect(f.componentInstance.notice()).toBe('');
    } finally {
      confirm.mockRestore();
    }
  });
  it('requires confirmation before revocation and refreshes the current family', async () => {
    const f = await setup();
    const device = {
      id: 4,
      public_id: 'synthetic-id',
      name: 'Synthetic phone',
      android_version: null,
      last_seen_at: null,
      revoked_at: null,
    };
    const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(false);
    await f.componentInstance.revoke(device);
    expect(api.revokeDevice).not.toHaveBeenCalled();
    confirm.mockReturnValue(true);
    await f.componentInstance.revoke(device);
    expect(api.revokeDevice).toHaveBeenCalledWith(1, 'synthetic-id');
    expect(f.componentInstance.notice()).toBe('Device access revoked.');
    confirm.mockRestore();
  });
});
