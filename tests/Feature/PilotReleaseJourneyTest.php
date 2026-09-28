<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\BankAccount;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Leasing\Models\LeaseServiceCharge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PilotReleaseJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_to_lease_to_bank_and_maintenance_stays_linked_and_tenant_scoped(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $foreignOrg = Organization::factory()->create();
        $foreignManager = User::factory()->create(['current_organization_id' => $foreignOrg->id]);
        $foreignOrg->users()->attach($foreignManager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $org->id, 'name' => 'Pilot month', 'starts_on' => today()->startOfMonth(), 'ends_on' => today()->endOfMonth()]);

        $this->actingAs($manager)->post(route('crm.leads.store'), ['first_name' => 'Pilot', 'last_name' => 'Prospect', 'email' => 'pilot@example.test'])->assertRedirect();
        $lead = $org->leads()->sole();
        $this->post(route('crm.leads.convert', $lead))->assertRedirect();
        $contactId = $lead->fresh()->converted_contact_id;
        $this->assertNotNull($contactId);

        $this->post(route('inventory.properties.store'), ['name' => 'Pilot Property', 'type' => 'residential'])->assertRedirect();
        $property = Property::where('organization_id', $org->id)->sole();
        $this->post(route('inventory.units.store'), ['property_id' => $property->id, 'number' => 'P-101', 'type' => 'apartment', 'status' => 'available'])->assertRedirect();
        $unit = Unit::where('organization_id', $org->id)->sole();
        $this->post(route('reservations.store'), ['unit_id' => $unit->id, 'contact_id' => $contactId, 'expires_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        $reservation = Reservation::where('organization_id', $org->id)->sole();
        $this->assertSame($contactId, $reservation->contact_id);
        $this->post(route('agreements.leases.store'), ['reservation_id' => $reservation->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addYear()->toDateString(), 'rent_amount' => 120000])->assertRedirect();
        $lease = Lease::where('organization_id', $org->id)->sole();
        $this->assertSame($contactId, $lease->contact_id);
        $this->post(route('agreements.leases.activate', $lease))->assertRedirect();
        $this->assertSame('leased', $unit->fresh()->status);

        $this->post(route('lease-compliance.service-charges.store', $lease), [
            'category' => 'service_charge',
            'period_starts_on' => today()->toDateString(),
            'period_ends_on' => today()->addMonth()->toDateString(),
            'net_amount' => 1000,
            'due_on' => today()->addWeek()->toDateString(),
        ])->assertRedirect();
        $charge = LeaseServiceCharge::where('organization_id', $org->id)->sole();
        $invoice = Invoice::where('organization_id', $org->id)->sole();
        $this->assertSame($lease->id, $charge->lease_id);
        $this->assertSame($invoice->id, $charge->invoice_id);
        $this->assertSame($contactId, $invoice->contact_id);
        $this->post(route('invoices.post', $invoice))->assertRedirect();
        $this->post(route('invoices.pay', $invoice), ['amount' => 1000])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);

        $bankLedger = LedgerAccount::create(['organization_id' => $org->id, 'code' => '1010', 'name' => 'Pilot Bank', 'type' => 'asset']);
        $bank = BankAccount::create(['organization_id' => $org->id, 'name' => 'Pilot Bank', 'currency' => 'AED', 'ledger_account_id' => $bankLedger->id]);
        $csv = "transaction_id,date,description,amount\nPILOT-RECEIPT,".today()->toDateString().",Service charge receipt,1000.00\n";
        $this->post(route('bank-reconciliation.import'), ['bank_account_id' => $bank->id, 'file' => UploadedFile::fake()->createWithContent('pilot.csv', $csv)])->assertRedirect();
        $statement = BankStatementLine::where('organization_id', $org->id)->sole();
        $clearing = JournalLine::whereHas('account', fn ($query) => $query->where('organization_id', $org->id)->where('code', '1150'))->sole();
        $this->post(route('bank-reconciliation.lines.match', $statement), ['journal_line_id' => $clearing->id])->assertRedirect();
        $this->post(route('bank-reconciliation.lines.settle', $statement))->assertRedirect();
        $this->assertDatabaseHas('journal_lines', ['ledger_account_id' => $bankLedger->id, 'debit' => '1000.00']);
        $this->assertDatabaseHas('bank_settlements', ['organization_id' => $org->id, 'bank_statement_line_id' => $statement->id]);

        $this->post(route('maintenance.store'), ['property_id' => $property->id, 'unit_id' => $unit->id, 'title' => 'Pilot inspection', 'priority' => 'medium'])->assertRedirect();
        $request = MaintenanceRequest::where('organization_id', $org->id)->sole();
        $this->put(route('maintenance.status.update', $request), ['status' => 'completed'])->assertRedirect();
        $this->assertSame('completed', $request->fresh()->status);

        $this->actingAs($foreignManager)->post(route('agreements.leases.activate', $lease))->assertNotFound();
        $this->post(route('bank-reconciliation.lines.settle', $statement))->assertNotFound();
        $this->put(route('maintenance.status.update', $request), ['status' => 'open'])->assertNotFound();
    }
}
