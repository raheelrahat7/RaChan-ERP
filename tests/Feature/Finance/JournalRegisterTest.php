<?php

namespace Tests\Feature\Finance;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JournalRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_csv_filter_detailed_aed_journals_and_escape_cells(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->subDays(2), 'ends_on' => today()->addDay()]);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => '=Cash', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        foreach ([today()->subDay()->toDateString(), today()->toDateString()] as $date) {
            $this->actingAs($manager)->post(route('accounting.journals.store'), ['posted_on' => $date, 'description' => '=Entry', 'lines' => [
                ['ledger_account_id' => $cash->id, 'description' => '=Line', 'debit' => '10', 'credit' => '0'],
                ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => '10'],
            ]])->assertRedirect();
        }
        JournalEntry::create(['organization_id' => $organization->id, 'reference' => 'LEGACY', 'event' => 'legacy.summary', 'posted_on' => today(), 'debit_total' => 5, 'credit_total' => 5, 'currency' => 'AED']);
        JournalEntry::create(['organization_id' => $other->id, 'reference' => 'FOREIGN', 'event' => 'manual.posted', 'posted_on' => today(), 'debit_total' => 5, 'credit_total' => 5, 'currency' => 'AED']);
        $date = today()->subDay()->toDateString();

        $this->actingAs($manager)->get(route('accounting.journal-register', ['from' => $date, 'to' => $date, 'event' => 'manual.posted']))
            ->assertInertia(fn (Assert $page) => $page->component('finance/JournalRegister')->has('entries.data', 1)->where('entries.data.0.posted_on', $date.'T00:00:00.000000Z'));
        $csv = $this->actingAs($manager)->get(route('accounting.journal-register.export', ['from' => $date, 'to' => $date, 'event' => 'manual.posted']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=Cash", $csv);
        $this->assertStringContainsString("'=Line", $csv);
        $this->assertStringNotContainsString('LEGACY', $csv);
        $this->assertStringNotContainsString('FOREIGN', $csv);
        $this->assertSame(2, substr_count($csv, $date));
        $this->actingAs($manager)->get(route('accounting.journal-register', ['event' => 'unknown']))->assertSessionHasErrors('event');
    }

    public function test_register_requires_finance_membership(): void
    {
        $organization = Organization::factory()->create();
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.journal-register'))->assertForbidden();
        $this->actingAs($outsider)->get(route('accounting.journal-register.export'))->assertForbidden();
    }
}
