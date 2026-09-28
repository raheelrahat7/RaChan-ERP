<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetDepreciation;
use App\Domain\Accounting\Models\FixedAssetDisposal;
use App\Domain\Accounting\Models\FixedAssetEstimateChange;
use App\Domain\Accounting\Models\FixedAssetImpairment;
use App\Domain\Accounting\Models\FixedAssetReview;
use App\Domain\Accounting\Models\FixedAssetTransfer;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FixedAssetRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_transfers_active_asset_without_posting_a_journal(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Marina Tower', 'type' => 'commercial']);
        $foreignProperty = Property::create(['organization_id' => $otherOrganization->id, 'name' => 'Foreign Tower', 'type' => 'commercial']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'CAP-TRANSFER', 'description' => 'Equipment', 'status' => 'posted', 'accounting_treatment' => 'capital_asset', 'bill_date' => '2026-09-01', 'total' => 1200, 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), ['source_type' => 'vendor_bill', 'vendor_bill_id' => $bill->id, 'reference' => 'FA-TRANSFER', 'name' => 'Transfer asset', 'asset_class' => 'Equipment', 'cost' => '1200.00', 'residual_value' => '0.00', 'useful_life_months' => 12, 'available_for_use_on' => '2026-09-01'])->assertRedirect();
        $asset = FixedAsset::sole();
        $journalCount = JournalEntry::count();
        $payload = ['transferred_on' => '2026-09-15', 'property_id' => $property->id, 'location' => 'Plant room', 'custodian' => 'Facilities team', 'asset_class' => 'Building equipment', 'reason' => 'Installed at operating site.'];

        $this->actingAs($manager)->post(route('accounting.fixed-assets.transfers.store', $asset), [...$payload, 'property_id' => $foreignProperty->id])->assertNotFound();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.transfers.store', $asset), $payload)->assertRedirect();

        $asset->refresh();
        $transfer = FixedAssetTransfer::sole();
        $this->assertSame($property->id, $asset->property_id);
        $this->assertSame('Plant room', $asset->location);
        $this->assertSame('Facilities team', $asset->custodian);
        $this->assertSame('Equipment', $transfer->from_asset_class);
        $this->assertSame('Building equipment', $transfer->to_asset_class);
        $this->assertSame($journalCount, JournalEntry::count());
        $this->actingAs($manager)->post(route('accounting.fixed-assets.transfers.store', $asset), $payload)->assertStatus(422);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index'))->assertInertia(fn (Assert $page) => $page->where('assets.0.property.name', 'Marina Tower')->where('transfers.0.reason', 'Installed at operating site.')->where('transfers.0.approver.name', $manager->name));
    }

    public function test_finance_manager_registers_sourced_asset_and_records_disposal_without_journal(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'CAP-1', 'description' => 'Equipment', 'status' => 'posted', 'accounting_treatment' => 'capital_asset', 'bill_date' => '2026-09-01', 'total' => 1000, 'currency' => 'AED']);
        $payload = ['source_type' => 'vendor_bill', 'vendor_bill_id' => $bill->id, 'reference' => 'FA-1', 'name' => 'Cooling unit', 'asset_class' => 'Plant and equipment', 'cost' => '600.00', 'residual_value' => '60.00', 'useful_life_months' => 60, 'available_for_use_on' => '2026-09-10'];

        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), $payload)->assertRedirect();
        $asset = FixedAsset::sole();
        $this->assertSame('ias16_cost_model', $asset->classification);
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.fixed_asset.registered', 'subject_id' => $asset->id]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), [...$payload, 'reference' => 'FA-2', 'cost' => '401.00'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.dispose', $asset), ['disposed_on' => '2026-09-09'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.dispose', $asset), ['disposed_on' => '2026-10-01'])->assertRedirect();
        $this->assertDatabaseHas('fixed_assets', ['id' => $asset->id, 'disposed_on' => '2026-10-01']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.fixed_asset.disposal_recorded', 'subject_id' => $asset->id]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => '2026-09']))->assertInertia(fn (Assert $page) => $page->component('finance/FixedAssets')->has('assets', 1)->where('assets.0.reference', 'FA-1')->where('assets.0.vendor_bill.reference', 'CAP-1')->where('depreciationPreview.month', '2026-09')->where('depreciationPreview.rows.0.active_days', 21)->where('depreciationPreview.rows.0.days_in_month', 30)->where('depreciationPreview.rows.0.proposed_charge', '6.30')->where('depreciationPreview.total', '6.30')->where('depreciationPreview.status', 'preview'));
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-09'])->assertRedirect();
        $this->assertDatabaseHas('fixed_asset_depreciations', ['fixed_asset_id' => $asset->id, 'month' => '2026-09', 'amount' => 6.30, 'approved_by' => $manager->id]);
        $journal = JournalEntry::where('event', 'depreciation.posted')->sole();
        $this->assertSame('6.30', $journal->debit_total);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal->id, 'ledger_account_id' => LedgerAccount::where('code', '5100')->sole()->id, 'debit' => 6.30]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal->id, 'ledger_account_id' => LedgerAccount::where('code', '1590')->sole()->id, 'credit' => 6.30]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-09'])->assertStatus(422);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => '2026-09']))->assertInertia(fn (Assert $page) => $page->has('depreciationPreview.rows', 0)->where('depreciationPreview.total', '0.00'));
        $depreciation = FixedAssetDepreciation::sole();
        $this->actingAs($manager)->post(route('accounting.journals.reverse', $journal), ['posted_on' => '2026-09-30'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.reverse', $depreciation), ['posted_on' => '2026-09-30'])->assertRedirect();
        $depreciation->refresh();
        $this->assertSame($manager->id, $depreciation->reversed_by);
        $this->assertDatabaseHas('journal_entries', ['id' => $depreciation->reversal_journal_entry_id, 'reversal_of_id' => $journal->id, 'event' => 'journal.reversed', 'debit_total' => 6.30, 'credit_total' => 6.30]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.depreciation.reversed', 'subject_id' => $depreciation->id]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.reverse', $depreciation), ['posted_on' => '2026-09-30'])->assertStatus(422);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => '2026-09']))->assertInertia(fn (Assert $page) => $page->where('depreciationPreview.rows.0.proposed_charge', '6.30')->where('depreciationPreview.total', '6.30')->where('depreciations.0.reversal_journal_entry_id', $depreciation->reversal_journal_entry_id));
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-09'])->assertRedirect();
        $this->assertDatabaseCount('fixed_asset_depreciations', 2);
        $this->assertSame(1, FixedAssetDepreciation::whereNull('reversal_journal_entry_id')->count());
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => 'invalid']))->assertSessionHasErrors('month');
    }

    public function test_opening_balance_source_and_tenant_permissions_are_enforced(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $capital = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1500', 'name' => 'Capital Assets', 'type' => 'asset']);
        $entry = JournalEntry::create(['organization_id' => $organization->id, 'reference' => 'OPEN-ENTRY', 'source_reference' => 'OPEN-2026', 'event' => 'opening.balance', 'posted_on' => '2026-09-01', 'debit_total' => 500, 'credit_total' => 500, 'currency' => 'AED']);
        $entry->lines()->create(['ledger_account_id' => $capital->id, 'description' => 'Asset opening', 'debit' => 500, 'credit' => 0]);
        $payload = ['source_type' => 'opening_balance', 'opening_source_reference' => 'OPEN-2026', 'reference' => 'FA-OPEN', 'name' => 'Office equipment', 'asset_class' => 'Equipment', 'cost' => '500.00', 'residual_value' => '0.00', 'useful_life_months' => 48, 'available_for_use_on' => '2026-09-01'];
        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('fixed_assets', ['opening_source_reference' => 'OPEN-2026', 'vendor_bill_id' => null]);
        $asset = FixedAsset::sole();
        $reviewPayload = ['review_year' => 2026, 'reviewed_on' => '2026-12-31', 'outcome' => 'change_required', 'impairment_assessment_required' => true, 'notes' => '=Obtain an impairment assessment before changing estimates.'];
        $this->actingAs($manager)->post(route('accounting.fixed-assets.reviews.store', $asset), $reviewPayload)->assertRedirect();
        $review = FixedAssetReview::sole();
        $this->assertSame('0.00', $review->residual_value_snapshot);
        $this->assertSame(48, $review->useful_life_months_snapshot);
        $this->assertTrue($review->impairment_assessment_required);
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.fixed_asset.reviewed', 'subject_id' => $review->id]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.reviews.store', $asset), $reviewPayload)->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.reviews.store', $asset), [...$reviewPayload, 'review_year' => 2027])->assertStatus(422);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['review_year' => 2026]))->assertInertia(fn (Assert $page) => $page->has('reviews', 1)->where('reviews.0.review_year', 2026)->where('reviews.0.outcome', 'change_required')->where('reviewReadiness.year', 2026)->where('reviewReadiness.eligible_count', 1)->has('reviewReadiness.missing', 0)->has('reviewReadiness.attention', 1)->where('reviewReadiness.attention.0.asset.reference', 'FA-OPEN'));
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['review_year' => 2027]))->assertInertia(fn (Assert $page) => $page->where('reviewReadiness.year', 2027)->has('reviewReadiness.missing', 1)->where('reviewReadiness.missing.0.reference', 'FA-OPEN')->has('reviewReadiness.attention', 0));
        $csv = $this->actingAs($manager)->get(route('accounting.fixed-assets.review-readiness.export', ['review_year' => 2026]))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Follow-up required",FA-OPEN,"Office equipment","change required",Required', $csv);
        $this->assertStringContainsString("'=Obtain an impairment assessment", $csv);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.review-readiness.export', ['review_year' => 2027]))->assertOk()->assertDownload('fixed-asset-review-readiness-2027.csv');
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['review_year' => 1999]))->assertSessionHasErrors('review_year');

        $member = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member)->post(route('accounting.fixed-assets.store'), $payload)->assertForbidden();
        $this->actingAs($member)->post(route('accounting.fixed-assets.reviews.store', $asset), [...$reviewPayload, 'review_year' => 2027, 'reviewed_on' => '2027-12-31'])->assertForbidden();
        $this->actingAs($member)->post(route('accounting.fixed-assets.estimate-changes.store', $review), ['residual_value' => '0.00', 'remaining_life_months' => 36])->assertForbidden();
        $this->actingAs($member)->get(route('accounting.fixed-assets.review-readiness.export', ['review_year' => 2026]))->assertOk();
        $other = Organization::factory()->create();
        $otherManager = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($otherManager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($otherManager)->post(route('accounting.fixed-assets.dispose', FixedAsset::sole()), ['disposed_on' => '2026-10-01'])->assertNotFound();
        $this->actingAs($otherManager)->post(route('accounting.fixed-assets.reviews.store', $asset), [...$reviewPayload, 'review_year' => 2027, 'reviewed_on' => '2027-12-31'])->assertNotFound();
        $this->actingAs($otherManager)->post(route('accounting.fixed-assets.estimate-changes.store', $review), ['residual_value' => '0.00', 'remaining_life_months' => 36])->assertNotFound();
    }

    public function test_approved_estimate_change_applies_prospectively_from_next_unposted_month(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'CAP-EST', 'description' => 'Equipment', 'status' => 'posted', 'accounting_treatment' => 'capital_asset', 'bill_date' => '2026-09-01', 'total' => 1200, 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), ['source_type' => 'vendor_bill', 'vendor_bill_id' => $bill->id, 'reference' => 'FA-EST', 'name' => 'Estimate asset', 'asset_class' => 'Equipment', 'cost' => '1200.00', 'residual_value' => '0.00', 'useful_life_months' => 12, 'available_for_use_on' => '2026-09-01'])->assertRedirect();
        $asset = FixedAsset::sole();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-09'])->assertRedirect();
        $this->assertDatabaseHas('fixed_asset_depreciations', ['month' => '2026-09', 'amount' => 100]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.reviews.store', $asset), ['review_year' => 2026, 'reviewed_on' => '2026-09-30', 'outcome' => 'change_required', 'impairment_assessment_required' => false, 'notes' => 'Revise prospectively.'])->assertRedirect();
        $review = FixedAssetReview::sole();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.estimate-changes.store', $review), ['residual_value' => '1100.01', 'remaining_life_months' => 10])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.estimate-changes.store', $review), ['residual_value' => '200.00', 'remaining_life_months' => 10])->assertRedirect();
        $change = FixedAssetEstimateChange::sole();
        $this->assertSame('2026-10', $change->effective_month);
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.fixed_asset.estimate_change_approved', 'subject_id' => $change->id]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.estimate-changes.store', $review), ['residual_value' => '200.00', 'remaining_life_months' => 10])->assertStatus(422);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page->where('depreciationPreview.rows.0.proposed_charge', '90.00')->where('depreciationPreview.rows.0.useful_life_months', 10)->where('depreciationPreview.rows.0.estimate_effective_month', '2026-10')->where('reviews.0.estimate_change.effective_month', '2026-10'));
        $this->assertDatabaseHas('fixed_asset_depreciations', ['month' => '2026-09', 'amount' => 100]);
    }

    public function test_manager_posts_and_reverses_asset_disposal_after_final_depreciation(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $receivable = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'CAP-DIS', 'description' => 'Equipment', 'status' => 'posted', 'accounting_treatment' => 'capital_asset', 'bill_date' => '2026-09-01', 'total' => 1200, 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), ['source_type' => 'vendor_bill', 'vendor_bill_id' => $bill->id, 'reference' => 'FA-DIS', 'name' => 'Disposal asset', 'asset_class' => 'Equipment', 'cost' => '1200.00', 'residual_value' => '0.00', 'useful_life_months' => 12, 'available_for_use_on' => '2026-09-01'])->assertRedirect();
        $asset = FixedAsset::sole();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-09'])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.dispose', $asset), ['disposed_on' => '2026-10-01'])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.disposals.post', $asset), ['proceeds_account_id' => $receivable->id, 'proceeds' => '1100.00'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-10'])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.disposals.post', $asset), ['proceeds_account_id' => $receivable->id, 'proceeds' => '1100.00'])->assertRedirect();
        $disposal = FixedAssetDisposal::sole();
        $this->assertSame('1096.77', $disposal->carrying_amount);
        $this->assertSame('3.23', $disposal->gain_loss);
        $journal = $disposal->journalEntry;
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal->id, 'ledger_account_id' => $receivable->id, 'debit' => 1100]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal->id, 'ledger_account_id' => LedgerAccount::where('code', '1500')->sole()->id, 'credit' => 1200]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal->id, 'ledger_account_id' => LedgerAccount::where('code', '4100')->sole()->id, 'credit' => 3.23]);
        $this->actingAs($manager)->post(route('accounting.journals.reverse', $journal), ['posted_on' => '2026-10-31'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.disposals.reverse', $disposal), ['posted_on' => '2026-10-31'])->assertRedirect();
        $this->assertDatabaseHas('fixed_asset_disposals', ['id' => $disposal->id, 'reversed_by' => $manager->id]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.disposals.post', $asset), ['proceeds_account_id' => $receivable->id, 'proceeds' => '1090.00'])->assertRedirect();
        $this->assertDatabaseCount('fixed_asset_disposals', 2);
    }

    public function test_manager_posts_impairment_and_future_depreciation_uses_reduced_basis(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $vendor = MaintenanceVendor::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $bill = VendorBill::create(['organization_id' => $organization->id, 'vendor_id' => $vendor->id, 'reference' => 'CAP-IMP', 'description' => 'Equipment', 'status' => 'posted', 'accounting_treatment' => 'capital_asset', 'bill_date' => '2026-09-01', 'total' => 1200, 'currency' => 'AED']);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.store'), ['source_type' => 'vendor_bill', 'vendor_bill_id' => $bill->id, 'reference' => 'FA-IMP', 'name' => 'Impaired asset', 'asset_class' => 'Equipment', 'cost' => '1200.00', 'residual_value' => '0.00', 'useful_life_months' => 12, 'available_for_use_on' => '2026-09-01'])->assertRedirect();
        $asset = FixedAsset::sole();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.depreciation.post'), ['month' => '2026-09'])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.reviews.store', $asset), ['review_year' => 2026, 'reviewed_on' => '2026-09-30', 'outcome' => 'unchanged', 'impairment_assessment_required' => true, 'notes' => 'Assessment supports AED 200 impairment.'])->assertRedirect();
        $review = FixedAssetReview::sole();
        $this->actingAs($manager)->post(route('accounting.fixed-assets.impairments.post', $review), ['posted_on' => '2026-09-30', 'amount' => '1100.01'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.impairments.post', $review), ['posted_on' => '2026-09-30', 'amount' => '200.00'])->assertRedirect();
        $impairment = FixedAssetImpairment::sole();
        $this->assertSame('2026-10', $impairment->effective_month);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $impairment->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '5300')->sole()->id, 'debit' => 200]);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $impairment->journal_entry_id, 'ledger_account_id' => LedgerAccount::where('code', '1580')->sole()->id, 'credit' => 200]);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.impairments.post', $review), ['posted_on' => '2026-10-01', 'amount' => '150.00'])->assertStatus(422);
        $this->assertDatabaseCount('fixed_asset_impairments', 1);
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page->where('depreciationPreview.rows.0.proposed_charge', '81.82')->where('depreciationPreview.rows.0.useful_life_months', 11)->where('impairments.0.effective_month', '2026-10'));
        $journal = JournalEntry::findOrFail($impairment->journal_entry_id);
        $this->actingAs($manager)->post(route('accounting.journals.reverse', $journal), ['posted_on' => '2026-10-01'])->assertStatus(422);
        $this->actingAs($manager)->post(route('accounting.fixed-assets.impairments.reverse', $impairment), ['posted_on' => '2026-10-01'])->assertRedirect();
        $this->actingAs($manager)->get(route('accounting.fixed-assets.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page->where('depreciationPreview.rows.0.proposed_charge', '100.00'));
        $this->actingAs($manager)->post(route('accounting.fixed-assets.impairments.post', $review), ['posted_on' => '2026-10-01', 'amount' => '150.00'])->assertRedirect();
        $this->assertDatabaseCount('fixed_asset_impairments', 2);
    }
}
