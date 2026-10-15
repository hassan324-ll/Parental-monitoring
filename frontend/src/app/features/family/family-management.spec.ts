import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
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
      listFamilies: vi.fn().mockReturnValue(of({
        data: [family],
        meta: { current_page: 1, last_page: 1, total: 1 },
      })),
      createFamily: vi.fn().mockReturnValue(of({ data: family })),
      listChildren: vi.fn().mockReturnValue(of({
        data: [{ id: 3, family_id: 7, display_name: 'Synthetic Child' }],
        meta: { current_page: 1, last_page: 1, total: 1 },
      })),
      createChild: vi.fn().mockReturnValue(of({
        data: { id: 4, family_id: 7, display_name: 'New Child' },
      })),
    };
    await TestBed.configureTestingModule({
      imports: [FamilyManagement],
      providers: [{ provide: FamilyApiService, useValue: api }],
    }).compileComponents();
  });

  async function setup() {
    const fixture = TestBed.createComponent(FamilyManagement);
    fixture.detectChanges();
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
    expect(api.listFamilies).toHaveBeenCalledTimes(2);
  });

  it('creates a child only inside the selected family', async () => {
    const fixture = await setup();
    fixture.componentInstance.childForm.controls.display_name.setValue(' New Child ');
    await fixture.componentInstance.createChild();
    expect(api.createChild).toHaveBeenCalledWith(7, 'New Child');
    expect(api.listChildren).toHaveBeenCalledTimes(2);
  });
});
