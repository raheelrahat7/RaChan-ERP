<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OffPlanPhase2dTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_overview_and_versioned_updates(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $developer = DB::table('offplan_developers')->insertGetId(['organization_id' => $org->id, 'name' => 'Harbour', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($owner)->postJson(route('offplan.projects.store'), [
            'developer_id' => $developer, 'code' => 'HB', 'name' => 'Harbour Bay', 'emirate' => 'Dubai',
            'launch_on' => '2027-01-01', 'handover_on' => '2030-01-01', 'commission_rate' => '2.50',
        ])->assertCreated()->assertJsonPath('project.version', 1)->assertJsonPath('project.units_total', 0)
            ->assertJsonPath('project.permissions.edit', true);
        $id = $response->json('project.id');

        $this->post(route('offplan.units.store', $id), ['number' => 'A1', 'price_aed' => '1200000.00'])->assertRedirect();
        $this->post(route('offplan.units.store', $id), ['number' => 'A2', 'price_aed' => '1500000.00'])->assertRedirect();
        $this->getJson(route('offplan.projects.data'))->assertOk()
            ->assertJsonPath('projects.data.0.units_total', 2)
            ->assertJsonPath('projects.data.0.units_available', 2)
            ->assertJsonPath('projects.data.0.units_sold', 0)
            ->assertJsonPath('projects.data.0.starting_price_aed', '1200000.00');

        $this->putJson(route('offplan.projects.update', $id), ['expected_version' => 1, 'workflow_status' => 'launched'])
            ->assertOk()->assertJsonPath('project.version', 2)->assertJsonPath('project.workflow_status', 'launched');
        $this->putJson(route('offplan.projects.update', $id), ['expected_version' => 1, 'workflow_status' => 'selling'])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson(route('offplan.projects.update', $id), ['expected_version' => 2, 'workflow_status' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('workflow_status');
        $this->getJson(route('offplan.projects.data', ['workflow_status' => 'launched']))->assertJsonPath('projects.total', 1)
            ->assertJsonPath('statusCounts.0.code', 'launched');
    }

    public function test_status_catalog_and_cross_organization_project_access(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $this->actingAs($owner)->postJson(route('offplan.project-statuses.store'), [
            'code' => 'presale', 'name' => 'Presale', 'position' => 2, 'active' => true,
        ])->assertCreated()->assertJsonPath('workflowStatus.version', 1);
        $status = DB::table('offplan_project_statuses')->where('organization_id', $org->id)->where('code', 'presale')->first();
        $this->putJson(route('offplan.project-statuses.update', $status->id), [
            'code' => 'presale', 'name' => 'Early Presale', 'position' => 2, 'active' => true, 'expected_version' => 1,
        ])->assertOk()->assertJsonPath('workflowStatus.version', 2);
        $this->putJson(route('offplan.project-statuses.update', $status->id), [
            'code' => 'presale', 'name' => 'Stale', 'position' => 2, 'active' => true, 'expected_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $foreign = DB::table('offplan_developers')->insertGetId(['organization_id' => $other->id, 'name' => 'Foreign', 'created_at' => now(), 'updated_at' => now()]);
        $foreignProject = DB::table('offplan_projects')->insertGetId([
            'organization_id' => $other->id, 'developer_id' => $foreign, 'code' => 'X', 'name' => 'Foreign',
            'emirate' => 'Dubai', 'commission_rate' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson(route('offplan.projects.show', $foreignProject))->assertNotFound();
        $this->putJson(route('offplan.projects.update', $foreignProject), ['expected_version' => 1, 'workflow_status' => 'selling'])->assertNotFound();
    }
}
