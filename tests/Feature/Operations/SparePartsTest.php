<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\ManageSpareParts;
use App\Domain\Operations\Actions\ManageStockTransfers;
use App\Domain\Operations\Models\SparePart;
use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockStore;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SparePartsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $technician;

    private SparePart $part;

    private StockStore $store;

    private MaintenanceRequest $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->technician = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->technician, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Local', 'type' => 'residential']);
        $this->job = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'reference' => 'MNT-STOCK', 'title' => 'Repair', 'assigned_to' => $this->technician->id, 'actual_cost' => '50.00']);
        $this->part = SparePart::create(['organization_id' => $this->organization->id, 'code' => 'PIPE', 'name' => 'Pipe', 'unit' => 'metre']);
        $this->store = StockStore::create(['organization_id' => $this->organization->id, 'code' => 'MAIN', 'name' => 'Main store']);
        $this->actingAs($this->manager);
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function input(string $type, string $quantity = '1', array $changes = []): array
    {
        return [...['type' => $type, 'quantity' => $quantity, 'spare_part_id' => $this->part->id, 'stock_store_id' => $this->store->id, 'maintenance_request_id' => $this->job->id, 'reference' => 'REF-TEST', 'operation_key' => (string) Str::uuid()], ...$changes];
    }

    private function receive(string $quantity = '10'): StockMovement
    {
        return app(ManageSpareParts::class)->movement($this->organization, $this->manager, $this->input('receipt', $quantity));
    }

    public function test_receipts_issues_and_returns_are_exact_per_store_and_do_not_post_finance(): void
    {
        $this->receive('10.125');
        $second = StockStore::create(['organization_id' => $this->organization->id, 'code' => 'SECOND', 'name' => 'Second store']);
        $this->post(route('stock.movements'), $this->input('receipt', '5', ['stock_store_id' => $second->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $this->input('issue', '2.125'))->assertRedirect()->assertSessionHasNoErrors();
        $issue = StockMovement::where('type', 'issue')->sole();
        $this->post(route('stock.movements'), $this->input('return', '0.125', ['related_movement_id' => $issue->id, 'stock_store_id' => $second->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8125, StockBalance::where('stock_store_id', $this->store->id)->sole()->quantity);
        $this->assertSame(5000, StockBalance::where('stock_store_id', $second->id)->sole()->quantity);
        $this->assertSame($this->store->id, StockMovement::where('type', 'return')->sole()->stock_store_id);
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->component('operations/SpareParts')->where('balances.0.available', '8.125')->has('movements.data', 4));
        $this->actingAs($this->technician)->get(route('maintenance.job-card', $this->job))->assertInertia(fn (Assert $page) => $page->has('stockMovements', 2)->where('stockMovements.0.part.code', 'PIPE')->where('stockMovements.1.quantity_display', '0.125'));
        $this->assertSame('50.00', $this->job->fresh()->actual_cost);
        $this->assertDatabaseCount('maintenance_job_cost_lines', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('vendor_bills', 0);
    }

    public function test_shortage_and_excess_returns_are_rejected_without_changing_balances(): void
    {
        $this->receive('1');
        $this->post(route('stock.movements'), $this->input('issue', '1.001'))->assertSessionHasErrors('quantity');
        $this->post(route('stock.movements'), $this->input('issue', '1'))->assertRedirect()->assertSessionHasNoErrors();
        $issue = StockMovement::where('type', 'issue')->sole();
        $this->post(route('stock.movements'), $this->input('return', '0.600', ['related_movement_id' => $issue->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $this->input('return', '0.401', ['related_movement_id' => $issue->id]))->assertSessionHasErrors('quantity');
        $this->assertSame(600, StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_operation_retries_are_idempotent_and_changed_payloads_are_rejected(): void
    {
        $input = $this->input('receipt', '3');
        $this->post(route('stock.movements'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), [...$input, 'quantity' => '4'])->assertSessionHasErrors('operation_key');
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(3000, StockBalance::sole()->quantity);
        $this->receive('2');
        $issue = $this->input('issue', '1');
        $this->post(route('stock.movements'), $issue)->assertRedirect()->assertSessionHasNoErrors();
        $this->job->update(['status' => 'completed']);
        $this->post(route('stock.movements'), $issue)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertSame(4000, StockBalance::sole()->quantity);
    }

    public function test_corrections_preserve_history_and_protect_consumed_receipts_and_returned_issues(): void
    {
        $receipt = $this->receive('5');
        $this->post(route('stock.movements'), $this->input('issue', '3'))->assertRedirect()->assertSessionHasNoErrors();
        $issue = StockMovement::where('type', 'issue')->sole();
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $receipt->id, 'reason' => 'Wrong receipt']))->assertSessionHasErrors('quantity');
        $this->post(route('stock.movements'), $this->input('return', '1', ['related_movement_id' => $issue->id]))->assertRedirect()->assertSessionHasNoErrors();
        $returned = StockMovement::where('type', 'return')->sole();
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $issue->id, 'reason' => 'Wrong issue']))->assertSessionHasErrors('related_movement_id');
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $returned->id]))->assertSessionHasErrors('reason');
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $returned->id, 'reason' => 'Correct return']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $issue->id, 'reason' => 'Correct issue']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5000, StockBalance::sole()->quantity);
        $this->assertNotNull($returned->fresh()->reversed_by_movement_id);
        $this->assertNotNull($issue->fresh()->reversed_by_movement_id);
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $issue->id, 'reason' => 'Again']))->assertSessionHasErrors('related_movement_id');
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $receipt->id, 'reason' => 'Correct receipt']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_movements', 6);
    }

    public function test_technicians_cannot_manage_stock_and_unrelated_jobs_are_hidden(): void
    {
        $this->receive();
        $this->post(route('stock.movements'), $this->input('issue'))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->get(route('stock.index'))->assertForbidden();
        $this->post(route('stock.movements'), $this->input('issue'))->assertForbidden();
        $this->post(route('stock.parts'), ['code' => 'OTHER', 'name' => 'Other', 'unit' => 'pcs'])->assertForbidden();
        $this->post(route('stock.stores'), ['code' => 'OTHER', 'name' => 'Other'])->assertForbidden();
        $this->get(route('maintenance.job-card', $this->job))->assertOk();
        $this->job->update(['assigned_to' => $this->manager->id]);
        $this->get(route('maintenance.job-card', $this->job))->assertNotFound();
    }

    public function test_foreign_parts_stores_jobs_and_original_movements_cannot_be_used(): void
    {
        $foreign = Organization::factory()->create();
        $part = SparePart::create(['organization_id' => $foreign->id, 'code' => 'PIPE', 'name' => 'Foreign', 'unit' => 'pcs']);
        $store = StockStore::create(['organization_id' => $foreign->id, 'code' => 'MAIN', 'name' => 'Foreign']);
        $this->post(route('stock.movements'), $this->input('receipt', '1', ['spare_part_id' => $part->id]))->assertNotFound();
        $this->post(route('stock.movements'), $this->input('receipt', '1', ['stock_store_id' => $store->id]))->assertNotFound();
        $this->job->update(['organization_id' => $foreign->id]);
        $this->post(route('stock.movements'), $this->input('issue'))->assertNotFound();
        $foreignMovement = StockMovement::create(['organization_id' => $foreign->id, 'spare_part_id' => $part->id, 'stock_store_id' => $store->id, 'type' => 'receipt', 'quantity' => 1000, 'delta' => 1000, 'reference' => 'Foreign', 'operation_key' => (string) Str::uuid()]);
        $this->post(route('stock.movements'), $this->input('reversal', '', ['related_movement_id' => $foreignMovement->id, 'reason' => 'Correction']))->assertNotFound();
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_submitted_completed_and_cancelled_jobs_require_reopening_for_stock_changes(): void
    {
        $this->receive();
        foreach (['completed', 'cancelled', 'in_progress'] as $status) {
            $this->job->update(['status' => $status, 'submitted_at' => $status === 'in_progress' ? now() : null]);
            $this->post(route('stock.movements'), $this->input('issue'))->assertSessionHasErrors('maintenance_request_id');
        }
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(10000, StockBalance::sole()->quantity);
        $this->post(route('maintenance.job-card.reopen', $this->job), ['reason' => 'More parts needed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $this->input('issue'))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_audit_failure_rolls_back_movement_and_balance(): void
    {
        $this->receive('2');
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageSpareParts::class)->movement($this->organization, $this->manager, $this->input('issue', '1'));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame(2000, StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfers_update_both_stores_and_retries_and_corrections_are_atomic(): void
    {
        $this->receive('5.125');
        $destination = StockStore::create(['organization_id' => $this->organization->id, 'code' => 'SECOND', 'name' => 'Second']);
        $input = ['spare_part_id' => $this->part->id, 'source_store_id' => $this->store->id, 'destination_store_id' => $destination->id, 'quantity' => '2.125', 'reference' => 'TRANSFER', 'operation_key' => (string) Str::uuid()];
        $this->post(route('stock.transfers'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.transfers'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.transfers'), [...$input, 'quantity' => '2'])->assertSessionHasErrors('operation_key');
        $out = StockMovement::where('type', 'transfer_out')->sole();
        $in = StockMovement::where('type', 'transfer_in')->sole();
        $this->assertSame($out->id, $in->related_movement_id);
        $this->assertSame(3000, StockBalance::where('stock_store_id', $this->store->id)->sole()->quantity);
        $this->assertSame(2125, StockBalance::where('stock_store_id', $destination->id)->sole()->quantity);
        $this->post(route('stock.movements'), $this->input('reversal', '1', ['related_movement_id' => $out->id, 'reason' => 'Wrong transfer']))->assertSessionHasErrors();
        $correction = ['reference' => 'CORRECT', 'reason' => 'Wrong destination', 'operation_key' => (string) Str::uuid()];
        $this->post(route('stock.transfers.reverse', $out), $correction)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.transfers.reverse', $out), $correction)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.transfers.reverse', $out), [...$correction, 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('transfer_movement_id');
        $this->post(route('stock.transfers'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5125, StockBalance::where('stock_store_id', $this->store->id)->sole()->quantity);
        $this->assertSame(0, StockBalance::where('stock_store_id', $destination->id)->sole()->quantity);
        $this->assertNotNull($out->fresh()->reversed_by_movement_id);
        $this->assertNotNull($in->fresh()->reversed_by_movement_id);
        $this->assertDatabaseCount('stock_movements', 5);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_transfer_shortages_scope_and_consumed_destination_block_without_partial_updates(): void
    {
        $this->receive('2');
        $destination = StockStore::create(['organization_id' => $this->organization->id, 'code' => 'SECOND', 'name' => 'Second']);
        $input = ['spare_part_id' => $this->part->id, 'source_store_id' => $this->store->id, 'destination_store_id' => $destination->id, 'quantity' => '3', 'reference' => 'TRANSFER', 'operation_key' => (string) Str::uuid()];
        $this->post(route('stock.transfers'), $input)->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('stock_balances', 1);
        $other = StockStore::create(['organization_id' => Organization::factory()->create()->id, 'code' => 'OTHER', 'name' => 'Other']);
        $this->post(route('stock.transfers'), [...$input, 'destination_store_id' => $other->id])->assertNotFound();
        $this->post(route('stock.transfers'), [...$input, 'destination_store_id' => $this->store->id])->assertSessionHasErrors('destination_store_id');
        $this->actingAs($this->technician)->post(route('stock.transfers'), $input)->assertForbidden();
        $this->actingAs($this->manager)->post(route('stock.transfers'), [...$input, 'quantity' => '2'])->assertRedirect()->assertSessionHasNoErrors();
        $out = StockMovement::where('type', 'transfer_out')->sole();
        $this->post(route('stock.movements'), $this->input('issue', '1', ['stock_store_id' => $destination->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.transfers.reverse', $out), ['reference' => 'VOID', 'reason' => 'Wrong store', 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('quantity');
        $this->assertSame(0, StockBalance::where('stock_store_id', $this->store->id)->sole()->quantity);
        $this->assertSame(1000, StockBalance::where('stock_store_id', $destination->id)->sole()->quantity);
        $this->assertNull($out->fresh()->reversed_by_movement_id);
        $this->assertDatabaseCount('stock_movements', 4);
    }

    public function test_transfer_audit_failure_rolls_back_both_legs(): void
    {
        $this->receive('2');
        $destination = StockStore::create(['organization_id' => $this->organization->id, 'code' => 'SECOND', 'name' => 'Second']);
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageStockTransfers::class)->transfer($this->organization, $this->manager, ['spare_part_id' => $this->part->id, 'source_store_id' => $this->store->id, 'destination_store_id' => $destination->id, 'quantity' => '1', 'reference' => 'TRANSFER', 'operation_key' => (string) Str::uuid()]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame(2000, StockBalance::sole()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_weighted_average_valuation_keeps_transfer_value_and_original_issue_return_cost(): void
    {
        $first = $this->receive('10');
        $second = $this->receive('10');
        $this->post(route('stock.receipt-cost', $first), ['amount' => '100.00', 'currency' => 'AED', 'reason' => 'Receipt invoice'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('balances.0.valuation.value', null));
        $this->post(route('stock.receipt-cost', $second), ['amount' => '200.00', 'currency' => 'AED', 'reason' => 'Receipt invoice'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $this->input('issue', '4'))->assertRedirect()->assertSessionHasNoErrors();
        $issue = StockMovement::where('type', 'issue')->sole();
        $third = $this->receive('4');
        $this->post(route('stock.receipt-cost', $third), ['amount' => '120.00', 'currency' => 'AED', 'reason' => 'Receipt invoice'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.movements'), $this->input('return', '2', ['related_movement_id' => $issue->id]))->assertRedirect()->assertSessionHasNoErrors();
        // 240 remaining + 120 new receipt + 30 at the original issue cost.
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('balances.0.valuation.value', '390.00')->where('balances.0.valuation.currency', 'AED'));
        $destination = StockStore::create(['organization_id' => $this->organization->id, 'code' => 'SECOND', 'name' => 'Second']);
        $this->post(route('stock.transfers'), ['spare_part_id' => $this->part->id, 'source_store_id' => $this->store->id, 'destination_store_id' => $destination->id, 'quantity' => '11', 'reference' => 'TRANSFER', 'operation_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('balances.0.valuation.value', '195.00')->where('balances.1.valuation.value', '195.00'));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->post(route('stock.receipt-cost', $first), ['amount' => '100', 'currency' => 'USD', 'reason' => 'Mismatch'])->assertSessionHasErrors('currency');
        $this->actingAs($this->technician)->post(route('stock.receipt-cost', $first), ['amount' => '100', 'currency' => 'AED', 'reason' => 'Denied'])->assertForbidden();
    }

    public function test_receipt_cost_corrections_recalculate_and_are_audited_without_hiding_unknown_costs(): void
    {
        $receipt = $this->receive('2');
        $input = ['amount' => '20.00', 'currency' => 'AED', 'reason' => 'Receipt invoice'];
        $this->post(route('stock.receipt-cost', $receipt), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('stock.receipt-cost', $receipt), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_receipt_costs', 1);
        $this->post(route('stock.receipt-cost', $receipt), [...$input, 'amount' => '30.00', 'reason' => 'Corrected invoice'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('balances.0.valuation.value', '30.00'));
        $this->post(route('stock.movements'), $this->input('issue', '1'))->assertRedirect()->assertSessionHasNoErrors();
        $issue = StockMovement::where('type', 'issue')->sole();
        $this->post(route('stock.receipt-cost', $issue), $input)->assertSessionHasErrors('stock_movement_id');
        $this->post(route('stock.movements'), $this->input('reversal', '1', ['related_movement_id' => $issue->id, 'reason' => 'Correct issue']))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('balances.0.valuation.value', '30.00'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.stock.receipt_cost_recorded']);
    }

    public function test_catalogue_codes_are_unique_per_organization_and_normalized(): void
    {
        $this->post(route('stock.parts'), ['code' => 'pipe', 'name' => 'Duplicate', 'unit' => 'pcs'])->assertSessionHasErrors('code');
        $this->post(route('stock.parts'), ['code' => ' filter ', 'name' => 'Filter', 'unit' => 'pcs'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('spare_parts', ['code' => 'FILTER', 'organization_id' => $this->organization->id]);
        $this->post(route('stock.stores'), ['code' => 'main', 'name' => 'Duplicate'])->assertSessionHasErrors('code');
    }
}
