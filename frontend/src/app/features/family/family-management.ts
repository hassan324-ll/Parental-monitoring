import { Component, OnInit, OnDestroy, inject, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { ChildProfile, Family, FamilyApiService } from '../../core/family-api.service';

@Component({
  selector: 'app-family-management',
  standalone: true,
  imports: [ReactiveFormsModule],
  templateUrl: './family-management.html',
  styleUrl: './family-management.scss',
})
export class FamilyManagement implements OnInit, OnDestroy {
  private readonly api = inject(FamilyApiService);
  private childrenRequest = 0;
  private selectionRequest = 0;
  private destroyed = false;

  readonly families = signal<Family[]>([]);
  readonly children = signal<ChildProfile[]>([]);
  readonly selectedFamilyId = signal<number | null>(null);
  readonly familyPage = signal(1);
  readonly familyLastPage = signal(1);
  readonly familyTotal = signal(0);
  readonly childPage = signal(1);
  readonly childLastPage = signal(1);
  readonly childTotal = signal(0);
  readonly loadingFamilies = signal(false);
  readonly loadingChildren = signal(false);
  readonly creatingFamily = signal(false);
  readonly creatingChild = signal(false);
  readonly familyError = signal('');
  readonly childError = signal('');
  readonly notice = signal('');

  readonly familyForm = new FormGroup({
    name: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(2), Validators.maxLength(100)],
    }),
  });

  readonly childForm = new FormGroup({
    display_name: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(100)],
    }),
  });

  readonly selectedFamily = () =>
    this.families().find((family) => family.id === this.selectedFamilyId()) ?? null;

  ngOnInit(): void {
    void this.loadFamilies();
  }

  ngOnDestroy(): void {
    this.destroyed = true;
    this.selectionRequest++;
    this.childrenRequest++;
  }

  async loadFamilies(page = 1, preferredFamilyId?: number): Promise<void> {
    if (this.destroyed || this.loadingFamilies()) return;
    this.loadingFamilies.set(true);
    this.familyError.set('');
    try {
      const result = await firstValueFrom(this.api.listFamilies(page));
      if (this.destroyed) return;
      this.families.set(result.data);
      this.familyPage.set(result.meta.current_page);
      this.familyLastPage.set(result.meta.last_page);
      this.familyTotal.set(result.meta.total);

      const currentId = preferredFamilyId ?? this.selectedFamilyId();
      const choice = result.data.find((family) => family.id === currentId) ?? result.data[0];
      if (choice) {
        await this.selectFamily(choice.id);
      } else {
        this.selectionRequest++;
        this.selectedFamilyId.set(null);
        this.childrenRequest++;
        this.children.set([]);
        this.childTotal.set(0);
      }
    } catch (error) {
      if (!this.destroyed) this.familyError.set(this.describeError(error));
    } finally {
      this.loadingFamilies.set(false);
    }
  }

  async selectFamily(id: number): Promise<void> {
    const exists = this.families().some((family) => family.id === id);
    if (this.destroyed || !exists) return;
    this.selectionRequest++;
    this.selectedFamilyId.set(id);
    this.children.set([]);
    this.childPage.set(1);
    this.childTotal.set(0);
    this.childError.set('');
    await this.loadChildren(id, 1);
  }

  async loadChildren(id = this.selectedFamilyId(), page = 1): Promise<void> {
    if (this.destroyed || id === null || id !== this.selectedFamilyId()) return;
    const request = ++this.childrenRequest;
    this.loadingChildren.set(true);
    this.childError.set('');
    try {
      const result = await firstValueFrom(this.api.listChildren(id, page));
      if (request !== this.childrenRequest || this.selectedFamilyId() !== id) return;
      this.children.set(result.data);
      this.childPage.set(result.meta.current_page);
      this.childLastPage.set(result.meta.last_page);
      this.childTotal.set(result.meta.total);
    } catch (error) {
      if (request === this.childrenRequest) this.childError.set(this.describeError(error));
    } finally {
      if (request === this.childrenRequest) this.loadingChildren.set(false);
    }
  }

  async createFamily(): Promise<void> {
    if (this.destroyed || this.creatingFamily()) return;
    const name = this.familyForm.controls.name.value.trim();
    if (name.length < 2 || name.length > 100 || this.familyForm.invalid) {
      this.familyForm.markAllAsTouched();
      return;
    }
    this.creatingFamily.set(true);
    this.familyError.set('');
    this.notice.set('');
    try {
      const created = await firstValueFrom(this.api.createFamily(name));
      if (this.destroyed) return;
      this.familyForm.reset();
      this.notice.set('Family created. You can now add a child profile.');
      await this.loadFamilies(1, created.data.id);
    } catch (error) {
      if (!this.destroyed) this.familyError.set(this.describeError(error));
    } finally {
      this.creatingFamily.set(false);
    }
  }

  async createChild(): Promise<void> {
    const familyId = this.selectedFamilyId();
    if (this.destroyed || familyId === null || this.creatingChild()) return;
    const displayName = this.childForm.controls.display_name.value.trim();
    if (!displayName || displayName.length > 100 || this.childForm.invalid) {
      this.childForm.markAllAsTouched();
      return;
    }
    const request = this.selectionRequest;
    this.creatingChild.set(true);
    this.childError.set('');
    this.notice.set('');
    try {
      await firstValueFrom(this.api.createChild(familyId, displayName));
      if (request !== this.selectionRequest) return;
      this.childForm.reset();
      this.notice.set('Child profile added. No device has been paired.');
      // Backend lists children by ascending ID; new profiles appear on the last page.
      const lastPage = Math.max(1, Math.ceil((this.childTotal() + 1) / 20));
      await this.loadChildren(familyId, lastPage);
    } catch (error) {
      if (request === this.selectionRequest) this.childError.set(this.describeError(error));
    } finally {
      this.creatingChild.set(false);
    }
  }

  private describeError(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 401) return 'Your session could not be verified. Sign in again.';
      if (error.status === 403) return 'Your family role does not permit that action.';
      if (error.status === 404) return 'This family is unavailable to your account.';
      if (error.status === 422) return 'Please check the entered details and try again.';
      if (error.status === 0) return 'Cannot reach Laravel. Check the backend connection.';
    }
    return 'The request could not be completed. Please try again.';
  }
}
