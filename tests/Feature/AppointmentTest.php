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

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_a_viewing_is_scoped_and_its_outcome_does_not_silently_move_the_lead(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $assignee = $this->member($org, OrganizationRole::Viewer);
        $foreign = $this->member(Organization::factory()->create(), OrganizationRole::Viewer);
        $lead = CrmLead::create(['organization_id' => $org->id, 'assigned_to' => $assignee->id, 'first_name' => 'Viewing', 'last_name' => 'Client']);
        $stageId = $lead->current_stage_id;
        $this->actingAs($owner)->post(route('meetings.store'), [
            'type' => 'viewing', 'title' => 'Canal viewing', 'assigned_to' => $assignee->id, 'lead_id' => $lead->id,
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertRedirect();
        $appointment = DB::table('brokerage_appointments')->sole();
        $this->actingAs($assignee)->get(route('meetings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('appointments.data.0.title', 'Canal viewing')->etc());
        $this->actingAs($foreign)->get(route('meetings.index'))->assertInertia(fn (Assert $page) => $page->has('appointments.data', 0)->etc());
        $this->actingAs($assignee)->post(route('meetings.outcome', $appointment->id), ['outcome' => 'Client requested another visit.'])->assertRedirect();
        $this->assertDatabaseHas('brokerage_appointments', ['id' => $appointment->id, 'status' => 'completed']);
        $this->assertSame($stageId, $lead->fresh()->current_stage_id);
    }

    public function test_foreign_lead_and_invalid_times_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $assignee = $this->member($org, OrganizationRole::Viewer);
        $foreignLead = CrmLead::create(['organization_id' => Organization::factory()->create()->id, 'first_name' => 'Foreign', 'last_name' => 'Lead']);
        $input = ['type' => 'meeting', 'title' => 'Wrong', 'assigned_to' => $assignee->id, 'lead_id' => $foreignLead->id,
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString()];
        $this->actingAs($owner)->post(route('meetings.store'), $input)->assertSessionHasErrors(['lead_id']);
        $this->post(route('meetings.store'), [...$input, 'ends_at' => now()->toDateTimeString()])->assertSessionHasErrors(['ends_at']);
        $this->assertDatabaseCount('brokerage_appointments', 0);
    }
}
