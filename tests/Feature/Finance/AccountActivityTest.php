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

class AccountActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_shows_opening_and_period_totals_with_reversals(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->subDays(3), 'ends_on' => today()->addDay()]);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        $lines = fn (string $amount) => [
            ['ledger_account_id' => $cash->id, 'debit' => $amount, 'credit' => '0'],
            ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => $amount],
        ];
        $this->actingAs($manager)->post(route('accounting.journals.store'), ['posted_on' => today()->subDays(2)->toDateString(), 'description' => 'Earlier', 'lines' => $lines('100.00')])->assertRedirect();
        $this->actingAs($manager)->post(route('accounting.journals.store'), ['posted_on' => today()->subDay()->toDateString(), 'description' => 'Later', 'lines' => $lines('50.00')])->assertRedirect();
        $later = JournalEntry::where('posted_on', today()->subDay()->toDateString())->sole();
        $this->actingAs($manager)->post(route('accounting.journals.reverse', $later), ['posted_on' => today()->toDateString()])->assertRedirect();

        $this->actingAs($manager)->get(route('accounting.activity', ['account_id' => $cash->id, 'from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->component('finance/AccountActivity')
                ->where('activity.opening_net', '100.00')->where('activity.period_debit', '50.00')
                ->where('activity.period_credit', '50.00')->where('activity.closing_net', '100.00')
                ->has('activity.lines.data', 2));
        $this->actingAs($manager)->get(route('accounting.activity', ['account_id' => $cash->id, 'to' => today()->subDays(2)->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->where('activity.period_debit', '100.00')->has('activity.lines.data', 1));
    }

    public function test_activity_rejects_foreign_account_and_non_member(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $foreign = LedgerAccount::create(['organization_id' => $other->id, 'code' => '1000', 'name' => 'Foreign', 'type' => 'asset']);

        $this->actingAs($manager)->get(route('accounting.activity', ['account_id' => $foreign->id]))->assertSessionHasErrors('account_id');
        $this->actingAs($manager)->get(route('accounting.activity', ['from' => '2026-09-15', 'to' => '2026-09-14']))->assertSessionHasErrors('to');
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actingAs($outsider)->get(route('accounting.activity'))->assertForbidden();
    }

    public function test_activity_csv_uses_the_same_filters_and_escapes_user_controlled_cells(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        AccountingPeriod::create(['organization_id' => $organization->id, 'name' => 'Current', 'starts_on' => today()->subDays(2), 'ends_on' => today()->addDay()]);
        $cash = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '1000', 'name' => '=Cash', 'type' => 'asset']);
        $equity = LedgerAccount::create(['organization_id' => $organization->id, 'code' => '3000', 'name' => 'Equity', 'type' => 'equity']);
        $foreign = LedgerAccount::create(['organization_id' => $other->id, 'code' => '9999', 'name' => 'Foreign', 'type' => 'asset']);
        foreach ([['date' => today()->subDay()->toDateString(), 'amount' => '10'], ['date' => today()->toDateString(), 'amount' => '5']] as $item) {
            $this->actingAs($manager)->post(route('accounting.journals.store'), ['posted_on' => $item['date'], 'description' => '=Formula', 'lines' => [
                ['ledger_account_id' => $cash->id, 'description' => '=Formula', 'debit' => $item['amount'], 'credit' => '0'],
                ['ledger_account_id' => $equity->id, 'debit' => '0', 'credit' => $item['amount']],
            ]])->assertRedirect();
        }

        $date = today()->subDay()->toDateString();
        $csv = $this->actingAs($manager)->get(route('accounting.activity.export', ['account_id' => $cash->id, 'from' => $date, 'to' => $date]))->assertOk()->streamedContent();
        $this->assertStringContainsString("Account,\"1000 · '=Cash\"", $csv);
        $this->assertStringContainsString("{$date}", $csv);
        $this->assertStringContainsString("'=Formula", $csv);
        $this->assertStringContainsString('10.00,0.00', $csv);
        $this->assertStringNotContainsString('5.00,0.00', $csv);
        $this->actingAs($manager)->get(route('accounting.activity.export', ['account_id' => $foreign->id]))->assertSessionHasErrors('account_id');
    }
}
