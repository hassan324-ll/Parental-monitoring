import { TestBed } from '@angular/core/testing';
import { of, Subject } from 'rxjs';
import { FamilyApiService } from '../../core/family-api.service';
import { FamilyManagement } from './family-management';

describe('family and child management', () => {
  const family = { id: 7, name: 'Synthetic Household', owner_id: 2, children_count: 1 };
  let api: {
    listFamilies: ReturnType<typeof vi.fn>;
    createFamily: ReturnType<typeof vi.fn>;
    listChildren: ReturnType<typeof vi.fn>;
    createChild: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    api = {
      listFamilies: vi.fn().mockReturnValue(
        of({
          data: [family],
          meta: { current_page: 1, last_page: 1, total: 1 },
        }),
      ),
      createFamily: vi.fn().mockReturnValue(of({ data: family })),
      listChildren: vi.fn().mockReturnValue(
        of({
          data: [{ id: 3, family_id: 7, display_name: 'Synthetic Child' }],
          meta: { current_page: 1, last_page: 1, total: 1 },
        }),
      ),
      createChild: vi.fn().mockReturnValue(
        of({
          data: { id: 4, family_id: 7, display_name: 'New Child' },
        }),
      ),
    };
    await TestBed.configureTestingModule({
      imports: [FamilyManagement],
      providers: [{ provide: FamilyApiService, useValue: api }],
    }).compileComponents();
  });

  async function setup() {
    const fixture = TestBed.createComponent(FamilyManagement);
    fixture.detectChanges();
    await vi.waitFor(() => {
      expect(fixture.componentInstance.loadingFamilies()).toBe(false);
      expect(fixture.componentInstance.loadingChildren()).toBe(false);
    });
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture;
  }

  it('loads only API-provided family and child profiles', async () => {
    const fixture = await setup();
    expect(api.listFamilies).toHaveBeenCalledWith(1);
    expect(api.listChildren).toHaveBeenCalledWith(7, 1);
    expect(fixture.nativeElement.textContent).toContain('Synthetic Household');
    expect(fixture.nativeElement.textContent).toContain('Synthetic Child');
  });

  it('preserves a newly edited family name while the submitted creation finishes', async () => {
    const f = await setup();
    const model = f.componentInstance;
    const response = new Subject<{ data: typeof family }>();
    api.createFamily.mockReturnValueOnce(response);
    model.familyForm.controls.name.setValue('Submitted household');
    const pending = model.createFamily();
    model.familyForm.controls.name.setValue('Next household draft');
    response.next({ data: family });
    await pending;
    expect(api.createFamily).toHaveBeenCalledWith('Submitted household');
    expect(model.familyForm.controls.name.value).toBe('Next household draft');
    expect(api.listFamilies).toHaveBeenCalledTimes(2);
    expect(model.notice()).toContain('Family created');
    expect(model.creatingFamily()).toBe(false);
  });

  it('preserves a newly edited child name while the submitted creation finishes', async () => {
    const f = await setup();
    const model = f.componentInstance;
    const response = new Subject<{
      data: { id: number; family_id: number; display_name: string };
    }>();
    api.createChild.mockReturnValueOnce(response);
    model.childForm.controls.display_name.setValue('Submitted child');
    const pending = model.createChild();
    model.childForm.controls.display_name.setValue('Next child draft');
    response.next({ data: { id: 9, family_id: 7, display_name: 'Submitted child' } });
    await pending;
    expect(api.createChild).toHaveBeenCalledWith(7, 'Submitted child');
    expect(model.childForm.controls.display_name.value).toBe('Next child draft');
    expect(api.listChildren).toHaveBeenCalledTimes(2);
    expect(model.notice()).toContain('Child profile added');
    expect(model.creatingChild()).toBe(false);
  });
  it('rejects invalid family names before calling the API', async () => {
    const fixture = await setup();
    fixture.componentInstance.familyForm.controls.name.setValue(' ');
    await fixture.componentInstance.createFamily();
    expect(api.createFamily).not.toHaveBeenCalled();
  });

  it('sends trimmed family creation and refreshes listing', async () => {
    const fixture = await setup();
    fixture.componentInstance.familyForm.controls.name.setValue(' New Household ');
    await fixture.componentInstance.createFamily();
    expect(api.createFamily).toHaveBeenCalledWith('New Household');
    expect(fixture.componentInstance.familyForm.controls.name.value).toBe('');
    expect(api.listFamilies).toHaveBeenCalledTimes(2);
  });

  it('preserves the current family draft when an older child creation succeeds', async () => {
    const f = await setup();
    const model = f.componentInstance;
    model.families.set([family, { ...family, id: 8, name: 'Other household' }]);
    const response = new Subject<{
      data: { id: number; family_id: number; display_name: string };
    }>();
    api.createChild.mockReturnValueOnce(response);
    model.childForm.controls.display_name.setValue('Old submission');
    const pending = model.createChild();
    await model.selectFamily(8);
    model.childForm.controls.display_name.setValue('Current draft');
    response.next({ data: { id: 9, family_id: 7, display_name: 'Old submission' } });
    await pending;
    expect(model.childForm.controls.display_name.value).toBe('Current draft');
    expect(model.notice()).toBe('');
    expect(model.creatingChild()).toBe(false);
  });

  it('ignores a child-creation failure after switching away and back', async () => {
    const f = await setup();
    const model = f.componentInstance;
    model.families.set([family, { ...family, id: 8, name: 'Other household' }]);
    const response = new Subject<unknown>();
    api.createChild.mockReturnValueOnce(response);
    model.childForm.controls.display_name.setValue('Old submission');
    const pending = model.createChild();
    await model.selectFamily(8);
    await model.selectFamily(7);
    response.error(new Error('Old submission failed'));
    await pending;
    expect(model.childError()).toBe('');
    expect(model.creatingChild()).toBe(false);
  });

  it('does not refresh children after destruction during child creation', async () => {
    const f = await setup();
    const model = f.componentInstance;
    const response = new Subject<{
      data: { id: number; family_id: number; display_name: string };
    }>();
    api.createChild.mockReturnValueOnce(response);
    model.childForm.controls.display_name.setValue('Pending child');
    const pending = model.createChild();
    const calls = api.listChildren.mock.calls.length;
    f.destroy();
    response.next({ data: { id: 9, family_id: 7, display_name: 'Pending child' } });
    await pending;
    expect(api.listChildren).toHaveBeenCalledTimes(calls);
    expect(model.notice()).toBe('');
  });
  it('still displays a current-family child-creation failure', async () => {
    const f = await setup();
    const model = f.componentInstance;
    const response = new Subject<unknown>();
    api.createChild.mockReturnValueOnce(response);
    model.childForm.controls.display_name.setValue('Current child');
    const pending = model.createChild();
    response.error(new Error('Current submission failed'));
    await pending;
    expect(model.childError()).toBe('The request could not be completed. Please try again.');
    expect(model.childForm.controls.display_name.value).toBe('Current child');
    expect(model.creatingChild()).toBe(false);
  });

  it('does not start child requests for a family-list response after destruction', async () => {
    const f = await setup();
    const response = new Subject<{
      data: (typeof family)[];
      meta: { current_page: number; last_page: number; total: number };
    }>();
    api.listFamilies.mockReturnValueOnce(response);
    const pending = f.componentInstance.loadFamilies();
    const calls = api.listChildren.mock.calls.length;
    f.destroy();
    response.next({ data: [family], meta: { current_page: 1, last_page: 1, total: 1 } });
    await pending;
    expect(api.listChildren).toHaveBeenCalledTimes(calls);
  });

  it('does not refresh families for a creation response after destruction', async () => {
    const f = await setup();
    const model = f.componentInstance;
    const response = new Subject<{ data: typeof family }>();
    api.createFamily.mockReturnValueOnce(response);
    model.familyForm.controls.name.setValue('Pending household');
    const pending = model.createFamily();
    const calls = api.listFamilies.mock.calls.length;
    f.destroy();
    response.next({ data: family });
    await pending;
    expect(api.listFamilies).toHaveBeenCalledTimes(calls);
    expect(model.notice()).toBe('');
  });
  it('creates a child only inside the selected family', async () => {
    const fixture = await setup();
    fixture.componentInstance.childForm.controls.display_name.setValue(' New Child ');
    await fixture.componentInstance.createChild();
    expect(api.createChild).toHaveBeenCalledWith(7, 'New Child');
    expect(fixture.componentInstance.childForm.controls.display_name.value).toBe('');
    expect(api.listChildren).toHaveBeenCalledTimes(2);
  });
});
