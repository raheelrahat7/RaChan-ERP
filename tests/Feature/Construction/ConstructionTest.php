<?php

namespace Tests\Feature\Construction;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Construction\Actions\ManageConstruction;
use App\Domain\Construction\Models\BoqItem;
use App\Domain\Construction\Models\BoqProgress;
use App\Domain\Construction\Models\ConstructionProject;
use App\Domain\Construction\Models\ContractorClaim;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConstructionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $owner;

    private ConstructionProject $project;

    private BoqItem $item;

    private MaintenanceVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create(['vat_enabled' => true]);
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->owner = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);
        $this->vendor = MaintenanceVendor::create(['organization_id' => $this->organization->id, 'name' => 'Contractor']);
        $construction = app(ManageConstruction::class);
        $this->project = $construction->project($this->organization, $this->manager, ['reference' => 'PRJ-1', 'title' => 'Renovation', 'budget' => '1000']);
        $this->item = $construction->item($this->organization, $this->manager, $this->project, ['reference' => 'PAINT', 'description' => 'Painting', 'unit' => 'metre', 'quantity' => '10', 'unit_rate' => '105']);
        $construction->progress($this->organization, $this->manager, $this->item, ['quantity' => '4', 'note' => 'Measured completed work', 'operation_key' => (string) Str::uuid()]);
        $this->actingAs($this->manager);
    }

    /** @return array<string,mixed> */
    private function input(string $quantity = '2.5'): array
    {
        return ['vendor_id' => $this->vendor->id, 'claimed_on' => today()->format('Y-m-d'), 'reason' => 'Progress claim', 'operation_key' => (string) Str::uuid(), 'lines' => [['item_id' => $this->item->id, 'quantity' => $quantity]]];
    }

    public function test_owner_approved_claim_preserves_gross_amount_through_finance_draft_and_posting(): void
    {
        $input = $this->input();
        $this->post(route('construction.claims', $this->project), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('construction.claims', $this->project), $input)->assertRedirect()->assertSessionHasNoErrors();
        $claim = ContractorClaim::sole();
        $this->assertSame(26250, $claim->amount_cents);
        $billInput = ['bill_date' => today()->format('Y-m-d'), 'accounting_treatment' => 'operating_expense', 'vat_treatment' => 'standard', 'input_vat_recoverable' => true];
        $this->post(route('construction.claims.bill', $claim), $billInput)->assertSessionHasErrors('claim');
        $this->post(route('construction.claims.approve', $claim))->assertForbidden();
        $this->actingAs($this->owner)->post(route('construction.claims.approve', $claim))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('construction.claims.bill', $claim), $billInput)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('construction.claims.bill', $claim), $billInput)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('vendor_bills', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        $bill = VendorBill::sole();
        $this->assertSame('262.50', $bill->total);
        $this->assertSame('12.50', $bill->vat_amount);
        $this->assertSame('draft', $bill->status);
        AccountingPeriod::create(['organization_id' => $this->organization->id, 'name' => 'Current', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);
        $this->post(route('vendor-bills.post', $bill))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('journal_lines', ['ledger_account_id' => LedgerAccount::where('code', '5000')->sole()->id, 'debit' => '250.00']);
        $this->get(route('construction.show', $this->project))->assertInertia(fn (Assert $page) => $page->where('project.over_budget', true)->where('claims.data.0.bill_status', 'posted'));
    }

    public function test_claims_reserve_completed_work_and_rejection_releases_progress_for_correction(): void
    {
        $this->post(route('construction.claims', $this->project), $this->input('4.001'))->assertSessionHasErrors('lines');
        $input = $this->input('4');
        $this->post(route('construction.claims', $this->project), $input)->assertRedirect()->assertSessionHasNoErrors();
        $claim = ContractorClaim::sole();
        $this->post(route('construction.claims', $this->project), $this->input('0.001'))->assertSessionHasErrors('lines');
        $this->post(route('construction.claims', $this->project), [...$input, 'reason' => 'Different'])->assertSessionHasErrors('operation_key');
        $progress = BoqProgress::sole();
        $this->post(route('construction.progress.void', $progress), ['reason' => 'Wrong measurement'])->assertSessionHasErrors('progress');
        $this->actingAs($this->owner)->post(route('construction.claims.reject', $claim), ['reason' => 'Correct measurement'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('construction.progress.void', $progress), ['reason' => 'Wrong measurement'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($progress->fresh()->voided_at);
        $this->assertSame(0, app(ManageConstruction::class)->completed($this->organization, $this->item));
        $this->post(route('construction.claims', $this->project), $this->input('1'))->assertSessionHasErrors('lines');
    }

    public function test_progress_boundaries_scope_and_owner_maker_checker_are_enforced(): void
    {
        $input = ['quantity' => '6', 'note' => 'Remainder complete', 'operation_key' => (string) Str::uuid()];
        $this->post(route('construction.progress', $this->item), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('construction.progress', $this->item), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('construction.progress', $this->item), [...$input, 'operation_key' => (string) Str::uuid(), 'quantity' => '0.001'])->assertSessionHasErrors('quantity');
        $this->actingAs($this->owner)->post(route('construction.claims', $this->project), $this->input('1'))->assertRedirect()->assertSessionHasNoErrors();
        $claim = ContractorClaim::sole();
        $this->post(route('construction.claims.approve', $claim))->assertSessionHasErrors('claim');
        $foreign = ConstructionProject::create(['organization_id' => Organization::factory()->create()->id, 'reference' => 'OTHER', 'title' => 'Other', 'budget_cents' => 0, 'created_by' => $this->owner->id]);
        $this->get(route('construction.show', $foreign))->assertNotFound();
        $member = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member)->get(route('construction.index'))->assertForbidden();
    }
}
