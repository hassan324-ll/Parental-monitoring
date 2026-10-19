import { SharingSummary } from './sharing-summary';
import { Component, OnInit, OnDestroy, inject, signal, computed } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { Family, ChildProfile, FamilyApiService } from '../../core/family-api.service';
import {
  DevicePairingApiService,
  PairedDevice,
  PairingInvitation,
} from '../../core/device-pairing-api.service';

@Component({
  selector: 'app-device-management',
  standalone: true,
  imports: [SharingSummary],
  templateUrl: './device-management.html',
})
export class DeviceManagement implements OnInit, OnDestroy {
  private readonly familiesApi = inject(FamilyApiService);
  private readonly pairingApi = inject(DevicePairingApiService);
  private requestId = 0;
  private readonly clock = signal(Date.now());
  private timer?: ReturnType<typeof setInterval>;
  readonly summaryDevice = signal<string | null>(null);
  readonly familyPage = signal(1);
  readonly familyLastPage = signal(1);
  readonly childPage = signal(1);
  readonly childLastPage = signal(1);
  readonly devicePage = signal(1);
  readonly deviceLastPage = signal(1);
  readonly families = signal<Family[]>([]);
  readonly selectedFamilyId = signal<number | null>(null);
  readonly children = signal<ChildProfile[]>([]);
  readonly devices = signal<PairedDevice[]>([]);
  readonly invitation = signal<PairingInvitation | null>(null);
  readonly loading = signal(false);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly invitationExpired = computed(() => {
    const invite = this.invitation();
    return !!invite && Date.parse(invite.expires_at) <= this.clock();
  });
  ngOnInit(): void {
    this.timer = setInterval(() => this.clock.set(Date.now()), 1000);
    void this.loadFamilies();
  }
  ngOnDestroy(): void {
    this.summaryDevice.set(null);
    clearInterval(this.timer);
    this.requestId++;
  }

  async loadFamilies(page = 1): Promise<void> {
    this.summaryDevice.set(null);
    const request = ++this.requestId;
    this.loading.set(true);
    this.error.set('');
    try {
      const response = await firstValueFrom(this.familiesApi.listFamilies(page));
      if (request !== this.requestId) return;
      this.families.set(response.data);
      this.familyPage.set(response.meta.current_page);
      this.familyLastPage.set(response.meta.last_page);
      if (response.data.length) await this.selectFamily(response.data[0].id);
      else {
        this.selectedFamilyId.set(null);
        this.invitation.set(null);
        this.children.set([]);
        this.devices.set([]);
      }
    } catch (e) {
      if (request === this.requestId) this.error.set(this.describe(e));
    } finally {
      if (request === this.requestId) this.loading.set(false);
    }
  }
  async selectFamily(id: number, childPage = 1, devicePage = 1): Promise<void> {
    if (!this.families().some((f) => f.id === id)) return;
    this.summaryDevice.set(null);
    const request = ++this.requestId;
    this.selectedFamilyId.set(id);
    this.invitation.set(null);
    this.children.set([]);
    this.devices.set([]);
    this.error.set('');
    this.notice.set('');
    this.loading.set(true);
    try {
      const [children, devices] = await Promise.all([
        firstValueFrom(this.familiesApi.listChildren(id, childPage)),
        firstValueFrom(this.pairingApi.listDevices(id, devicePage)),
      ]);
      if (request !== this.requestId) return;
      this.childPage.set(children.meta.current_page);
      this.childLastPage.set(children.meta.last_page);
      this.devicePage.set(devices.meta.current_page);
      this.deviceLastPage.set(devices.meta.last_page);
      this.children.set(children.data);
      this.devices.set(devices.data);
    } catch (e) {
      if (request === this.requestId) this.error.set(this.describe(e));
    } finally {
      if (request === this.requestId) this.loading.set(false);
    }
  }
  async createInvitation(childId: number): Promise<void> {
    const familyId = this.selectedFamilyId();
    if (
      familyId === null ||
      this.busy() ||
      this.loading() ||
      !this.children().some((child) => child.id === childId)
    )
      return;
    const request = this.requestId;
    this.busy.set(true);
    this.error.set('');
    this.invitation.set(null);
    try {
      const result = await firstValueFrom(this.pairingApi.createInvitation(familyId, childId));
      if (request === this.requestId) {
        this.clock.set(Date.now());
        this.invitation.set(result);
      }
    } catch (e) {
      if (request === this.requestId) this.error.set(this.describe(e));
    } finally {
      this.busy.set(false);
    }
  }
  async revoke(device: PairedDevice): Promise<void> {
    const familyId = this.selectedFamilyId();
    if (familyId === null || this.busy() || device.revoked_at) return;
    const request = this.requestId;
    if (
      !globalThis.confirm('Revoke access for ' + device.name + '? The device will be disconnected.')
    )
      return;
    this.busy.set(true);
    this.error.set('');
    try {
      this.summaryDevice.set(null);
      await firstValueFrom(this.pairingApi.revokeDevice(familyId, device.public_id));
      if (request === this.requestId) {
        const refresh = this.selectFamily(familyId, this.childPage(), this.devicePage());
        const refreshRequest = this.requestId;
        await refresh;
        if (refreshRequest === this.requestId) this.notice.set('Device access revoked.');
      }
    } catch (e) {
      if (request === this.requestId) this.error.set(this.describe(e));
    } finally {
      this.busy.set(false);
    }
  }
  private describe(e: unknown): string {
    if (e instanceof HttpErrorResponse) {
      if (e.status === 401) return 'Session expired. Please sign in again.';
      if (e.status === 403) return 'You do not have permission for this action.';
      if (e.status === 429) return 'Too many requests. Please wait before trying again.';
      if (e.status === 422) return 'Please check the selected child and try again.';
      if (e.status === 404) return 'The requested family or device was not found.';
      if (e.status === 0) return 'Backend unavailable. Check the Laravel connection.';
    }
    return 'Request failed. Please try again.';
  }
}
