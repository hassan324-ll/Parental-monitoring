import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { Family, ChildProfile, FamilyApiService } from '../../core/family-api.service';
import { DevicePairingApiService, PairedDevice, PairingInvitation } from '../../core/device-pairing-api.service';

@Component({
  selector: 'app-device-management',
  standalone: true,
  templateUrl: './device-management.html',
})
export class DeviceManagement implements OnInit {
  private readonly familiesApi = inject(FamilyApiService);
  private readonly pairingApi = inject(DevicePairingApiService);
  private requestId = 0;
  readonly families = signal<Family[]>([]);
  readonly selectedFamilyId = signal<number | null>(null);
  readonly children = signal<ChildProfile[]>([]);
  readonly devices = signal<PairedDevice[]>([]);
  readonly invitation = signal<PairingInvitation | null>(null);
  readonly loading = signal(false);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  ngOnInit(): void { void this.loadFamilies(); }
  async loadFamilies(): Promise<void> {
    this.loading.set(true);
    this.error.set('');
    try {
      const response = await firstValueFrom(this.familiesApi.listFamilies());
      this.families.set(response.data);
      if (response.data.length) await this.selectFamily(response.data[0].id);
    } catch (e) { this.error.set(this.describe(e)); }
    finally { this.loading.set(false); }
  }
  async selectFamily(id: number): Promise<void> {
    if (!this.families().some(f => f.id === id)) return;
    const request = ++this.requestId;
    this.selectedFamilyId.set(id);
    this.invitation.set(null);
    this.children.set([]);
    this.devices.set([]);
    this.error.set('');
    this.loading.set(true);
    try {
      const [children, devices] = await Promise.all([
        firstValueFrom(this.familiesApi.listChildren(id)),
        firstValueFrom(this.pairingApi.listDevices(id)),
      ]);
      if (request !== this.requestId) return;
      this.children.set(children.data);
      this.devices.set(devices.data);
    } catch (e) { if (request === this.requestId) this.error.set(this.describe(e)); }
    finally { if (request === this.requestId) this.loading.set(false); }
  }
  async createInvitation(childId: number): Promise<void> {
    const familyId = this.selectedFamilyId();
    if (familyId === null || this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    this.invitation.set(null);
    try {
      const result = await firstValueFrom(this.pairingApi.createInvitation(familyId, childId));
      if (this.selectedFamilyId() === familyId) this.invitation.set(result);
    } catch (e) { this.error.set(this.describe(e)); }
    finally { this.busy.set(false); }
  }
  async revoke(device: PairedDevice): Promise<void> {
    const familyId = this.selectedFamilyId();
    if (familyId === null || this.busy() || device.revoked_at) return;
    if (!globalThis.confirm('Revoke access for ' + device.name + '? The device will be disconnected.')) return;
    this.busy.set(true);
    this.error.set('');
    try {
      await firstValueFrom(this.pairingApi.revokeDevice(familyId, device.public_id));
      this.notice.set('Device access revoked.');
      if (this.selectedFamilyId() === familyId) await this.selectFamily(familyId);
    } catch (e) { this.error.set(this.describe(e)); }
    finally { this.busy.set(false); }
  }
  private describe(e: unknown): string {
    if (e instanceof HttpErrorResponse) {
      if (e.status === 401) return 'Session expired. Please sign in again.';
      if (e.status === 403) return 'You do not have permission for this action.';
      if (e.status === 404) return 'The requested family or device was not found.';
      if (e.status === 0) return 'Backend unavailable. Check the Laravel connection.';
    }
    return 'Request failed. Please try again.';
  }
}
