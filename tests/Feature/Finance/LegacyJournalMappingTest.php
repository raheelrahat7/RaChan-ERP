<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\CorporateTaxReturn;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Models\LegacyJournalMapping;
use App\Domain\Accounting\Models\VatReturn;
use App\Domain\Accounting\Queries\PeriodCloseReadiness;
use App\Domain\Accounting\Queries\TrialBalance;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegacyJournalMappingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private User $owner;

    private User $accountant;

    private JournalEntry $source;

    private AccountingPeriod $period;

    private LedgerAccount $receivable;

    private LedgerAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 27));
        $this->org = Organization::factory()->create();
        foreach (['manager' => OrganizationRole::Manager, 'owner' => OrganizationRole::Owner, 'accountant' => OrganizationRole::Viewer] as $name => $role) {
            $this->$name = User::factory()->create(['current_organization_id' => $this->org->id]);
            $this->org->users()->attach($this->$name, ['role' => $role->value]);
        }
        $this->period = AccountingPeriod::create(['organization_id' => $this->org->id, 'name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $this->receivable = LedgerAccount::create(['organization_id' => $this->org->id, 'code' => '1100', 'name' => 'Receivable', 'type' => 'asset', 'is_active' => true]);
        $this->revenue = LedgerAccount::create(['organization_id' => $this->org->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'income', 'is_active' => true]);
        $this->source = JournalEntry::create(['organization_id' => $this->org->id, 'reference' => 'LEGACY-1', 'event' => 'legacy.imported', 'posted_on' => '2026-09-10', 'debit_total' => 100, 'credit_total' => 100, 'currency' => 'AED']);
    }

    /** @return array<string, mixed> */
    private function proposal(): array
    {
        return ['journal_entry_id' => $this->source->id, 'reason' => 'Reviewed original allocation', 'evidence_reference' => 'Source register 12', 'lines' => [
            ['ledger_account_id' => $this->receivable->id, 'debit' => '100.00', 'credit' => '0.00'],
            ['ledger_account_id' => $this->revenue->id, 'debit' => '0.00', 'credit' => '100.00'],
        ]];
    }

    private function submit(): LegacyJournalMapping
    {
        $this->actingAs($this->manager)->post(route('accounting.legacy-mappings.store'), $this->proposal())->assertRedirect()->assertSessionHasNoErrors();

        return LegacyJournalMapping::latest('id')->firstOrFail();
    }

    public function test_owner_approval_activates_original_date_and_totals_without_duplicate_journal(): void
    {
        $mapping = $this->submit();
        $this->assertSame(0, $this->source->lines()->count());
        $this->assertTrue(app(PeriodCloseReadiness::class)->for($this->period)['has_ledger_blocker']);
        $this->actingAs($this->manager)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Approve'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Source reconciles'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('journal_entries', ['id' => $this->source->id, 'posted_on' => '2026-09-10', 'debit_total' => 100, 'credit_total' => 100]);
        $this->assertFalse(app(PeriodCloseReadiness::class)->for($this->period)['has_ledger_blocker']);
        $before = app(TrialBalance::class)->forOrganization($this->org, '2026-09-09')->firstWhere('id', $this->receivable->id);
        $after = app(TrialBalance::class)->forOrganization($this->org, '2026-09-10')->firstWhere('id', $this->receivable->id);
        $this->assertSame(0.0, (float) $before['debit']);
        $this->assertSame(100.0, (float) $after['debit']);
        $this->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Repeat'])->assertSessionHasErrors('mapping');
        $this->post(route('accounting.journals.reverse', $this->source), ['posted_on' => '2026-09-20'])->assertStatus(422);
        $this->post(route('accounting.legacy-mappings.reverse', $mapping), ['posted_on' => '2026-09-20', 'reason' => 'Correction'])->assertRedirect();
        $this->assertDatabaseHas('legacy_journal_mappings', ['id' => $mapping->id, 'status' => 'reversed']);
        $this->assertSame(2, $this->source->lines()->count());
        $this->assertSame(100.0, (float) app(TrialBalance::class)->forOrganization($this->org, '2026-09-20')->firstWhere('id', $this->receivable->id)['credit']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.mapping.approved', 'actor_id' => $this->owner->id]);
    }

    public function test_owner_can_grant_and_revoke_accountant_approval_without_other_finance_permissions(): void
    {
        $mapping = $this->submit();
        $this->actingAs($this->accountant)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertForbidden();
        $this->actingAs($this->manager)->put(route('accounting.legacy-mappings.delegate'), ['user_id' => $this->accountant->id, 'allowed' => true])->assertForbidden();
        $this->actingAs($this->owner)->put(route('accounting.legacy-mappings.delegate'), ['user_id' => $this->accountant->id, 'allowed' => true])->assertRedirect();
        $this->actingAs($this->accountant)->get(route('accounting.legacy-mappings.index'))->assertInertia(fn (Assert $page) => $page->where('canApprove', true)->where('canDelegate', false)->where('canSubmit', false));
        $this->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed source'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('accounting.journals.store'), [])->assertForbidden();
        $this->actingAs($this->owner)->put(route('accounting.legacy-mappings.delegate'), ['user_id' => $this->accountant->id, 'allowed' => false])->assertRedirect();
        $this->actingAs($this->accountant)->post(route('accounting.legacy-mappings.reverse', $mapping), ['posted_on' => '2026-09-20', 'reason' => 'Correction'])->assertForbidden();
    }

    public function test_invalid_allocations_source_changes_and_closed_periods_do_not_write_lines(): void
    {
        $input = $this->proposal();
        $input['lines'][0]['debit'] = 90;
        $this->actingAs($this->manager)->post(route('accounting.legacy-mappings.store'), $input)->assertSessionHasErrors('lines');
        $mapping = $this->submit();
        $this->source->update(['reference' => 'SOURCE-CHANGED']);
        $this->actingAs($this->owner)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertSessionHasErrors('mapping');
        $this->source->update(['reference' => 'LEGACY-1']);
        $this->period->update(['status' => 'closed']);
        $this->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertSessionHasErrors('mapping');
        $this->period->update(['status' => 'open']);
        $this->revenue->update(['is_active' => false]);
        $this->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_filed_returns_block_activation_without_changing_their_snapshots(): void
    {
        $mapping = $this->submit();
        $vat = VatReturn::create(['organization_id' => $this->org->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'filed', 'snapshot' => ['reviewed' => true], 'prepared_by' => $this->manager->id]);
        $this->actingAs($this->owner)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertSessionHasErrors('mapping');
        $this->assertSame(['reviewed' => true], $vat->fresh()->snapshot);
        $vat->update(['status' => 'prepared']);
        CorporateTaxReturn::create(['organization_id' => $this->org->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'filed', 'profile' => 'standard', 'accounting_profit' => 0, 'revenue' => 0, 'taxable_income' => 0, 'tax_payable' => 0, 'adjustment_notes' => 'Reviewed', 'prepared_by' => $this->manager->id]);
        $this->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertSessionHasErrors('mapping');
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertSame('submitted', $mapping->fresh()->status);
    }

    public function test_removing_and_readding_a_member_does_not_restore_delegated_authority(): void
    {
        $mapping = $this->submit();
        $this->actingAs($this->owner)->put(route('accounting.legacy-mappings.delegate'), ['user_id' => $this->accountant->id, 'allowed' => true])->assertRedirect();
        $this->delete(route('organization.members.destroy', $this->accountant))->assertRedirect();
        $this->org->users()->attach($this->accountant, ['role' => OrganizationRole::Viewer->value]);
        $this->actingAs($this->accountant)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting.mapping.approver_revoked']);
    }

    public function test_rejection_and_resubmission_preserve_history_and_one_pending_proposal(): void
    {
        $mapping = $this->submit();
        $this->post(route('accounting.legacy-mappings.store'), $this->proposal())->assertSessionHasErrors('mapping');
        $this->actingAs($this->owner)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => false, 'reason' => 'Correct source evidence'])->assertRedirect()->assertSessionHasNoErrors();
        $replacement = $this->submit();
        $this->assertNotSame($mapping->id, $replacement->id);
        $this->assertSame('rejected', $mapping->fresh()->status);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('legacy_journal_mappings', 2);
    }

    public function test_foreign_accounts_and_non_aed_sources_cannot_be_allocated(): void
    {
        $other = Organization::factory()->create();
        $foreign = LedgerAccount::create(['organization_id' => $other->id, 'code' => '1100', 'name' => 'Other receivable', 'type' => 'asset', 'is_active' => true]);
        $input = $this->proposal();
        $input['lines'][0]['ledger_account_id'] = $foreign->id;
        $this->actingAs($this->manager)->post(route('accounting.legacy-mappings.store'), $input)->assertSessionHasErrors('lines');
        $this->source->update(['currency' => 'USD']);
        $this->post(route('accounting.legacy-mappings.store'), $this->proposal())->assertSessionHasErrors('mapping');
        $this->assertDatabaseCount('legacy_journal_mappings', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_mapping_reversal_requires_unmatching_bank_rows(): void
    {
        $mapping = $this->submit();
        $this->actingAs($this->owner)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reconciled'])->assertRedirect()->assertSessionHasNoErrors();
        $bank = BankAccount::create(['organization_id' => $this->org->id, 'name' => 'Bank', 'currency' => 'AED']);
        $row = BankStatementLine::create(['organization_id' => $this->org->id, 'bank_account_id' => $bank->id, 'imported_by' => $this->manager->id, 'import_batch' => '00000000-0000-0000-0000-000000000001', 'external_id' => 'BANK-1', 'occurred_on' => '2026-09-10', 'description' => 'Historical payment', 'amount' => 100, 'matched_journal_line_id' => $this->source->lines()->firstOrFail()->id]);
        $this->post(route('accounting.legacy-mappings.reverse', $mapping), ['posted_on' => '2026-09-20', 'reason' => 'Correction'])->assertSessionHasErrors('mapping');
        $this->assertDatabaseCount('journal_entries', 1);
        $row->update(['matched_journal_line_id' => null]);
        $this->post(route('accounting.legacy-mappings.reverse', $mapping), ['posted_on' => '2026-09-20', 'reason' => 'Correction'])->assertRedirect();
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_self_approval_and_foreign_access_are_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('accounting.legacy-mappings.store'), $this->proposal())->assertRedirect();
        $mapping = LegacyJournalMapping::sole();
        $this->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertSessionHasErrors('mapping');
        $other = Organization::factory()->create();
        $stranger = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($stranger, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($stranger)->post(route('accounting.legacy-mappings.decide', $mapping), ['approve' => true, 'reason' => 'Reviewed'])->assertNotFound();
        $this->post(route('accounting.legacy-mappings.store'), $this->proposal())->assertNotFound();
        $this->put(route('accounting.legacy-mappings.delegate'), ['user_id' => $this->accountant->id, 'allowed' => true])->assertNotFound();
    }
}
