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
