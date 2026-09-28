<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OffPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(Organization $org): User
    {
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);

        return $owner;
    }

    public function test_offplan_inventory_milestones_and_deal_transitions_remain_operational(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->owner($org);
        $this->actingAs($owner)->post(route('real-estate.people.store', 'developers'), ['name' => 'Coastal Developments'])->assertRedirect();
        $developer = DB::table('offplan_developers')->sole();
        $this->post(route('offplan.projects.store'), ['developer_id' => $developer->id, 'code' => 'COAST', 'name' => 'Coastal One', 'emirate' => 'Dubai'])->assertRedirect();
        $project = DB::table('offplan_projects')->sole();
        $this->post(route('offplan.units.store', $project->id), ['number' => 'A-101', 'price_aed' => '1250000.00'])->assertRedirect();
        $unit = DB::table('offplan_units')->sole();
        $this->post(route('offplan.milestones.store', $project->id), ['sequence' => 1, 'label' => 'Booking', 'percentage' => '20.00'])->assertRedirect();
        $this->post(route('offplan.milestones.store', $project->id), ['sequence' => 2, 'label' => 'Handover', 'percentage' => '81.00'])->assertSessionHasErrors(['percentage']);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $owner->id, 'first_name' => 'Amina', 'last_name' => 'Buyer']);
        $this->post(route('offplan.deals.store'), ['unit_id' => $unit->id, 'lead_id' => $lead->id, 'reference' => 'OP-001'])->assertRedirect();
        $deal = DB::table('offplan_deals')->sole();
        $this->post(route('offplan.deals.status', $deal->id), ['status' => 'reserved'])->assertRedirect();
        $this->post(route('offplan.deals.status', $deal->id), ['status' => 'contracted', 'contracted_on' => now()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('offplan_units', ['id' => $unit->id, 'status' => 'sold']);
        $this->assertDatabaseHas('offplan_deals', ['id' => $deal->id, 'status' => 'contracted']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->get(route('offplan.show', $project->id))->assertInertia(fn (Assert $page) => $page
            ->where('project.name', 'Coastal One')->where('units.data.0.status', 'sold')->has('milestones', 1)->etc());
    }

    public function test_cross_organization_developer_unit_and_lead_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = $this->owner($org);
        $foreignDeveloper = DB::table('offplan_developers')->insertGetId(['organization_id' => $other->id, 'name' => 'Foreign', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->post(route('offplan.projects.store'), ['developer_id' => $foreignDeveloper, 'code' => 'X', 'name' => 'Wrong', 'emirate' => 'Dubai'])->assertSessionHasErrors(['developer_id']);
        $this->assertDatabaseCount('offplan_projects', 0);
    }
}
