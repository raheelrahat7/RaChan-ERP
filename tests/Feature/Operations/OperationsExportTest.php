<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OperationsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_csv_escapes_spreadsheet_cells_and_excludes_other_organizations(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user, ['role' => OrganizationRole::Viewer->value]);
        $property = Property::create(['organization_id' => $organization->id, 'name' => 'Local', 'type' => 'residential']);
        foreach (['=SUM(1,1)', '+Formula', '-Formula', '@Formula', "\t=Formula", 'Ordinary text'] as $index => $title) {
            MaintenanceRequest::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'reference' => 'LOCAL-'.$index, 'title' => $title, 'assigned_to' => $user->id]);
        }
        $foreign = Organization::factory()->create();
        $foreignProperty = Property::create(['organization_id' => $foreign->id, 'name' => 'Foreign', 'type' => 'residential']);
        MaintenanceRequest::create(['organization_id' => $foreign->id, 'property_id' => $foreignProperty->id, 'reference' => 'SECRET', 'title' => 'Secret title']);
        $csv = $this->actingAs($user)->get(route('reports.export', ['report' => 'maintenance']))->assertOk()->streamedContent();
        $rows = array_map(fn (string $line): array => str_getcsv($line), explode("\n", trim($csv)));
        $this->assertCount(6, $rows);
        $this->assertSame("'=SUM(1,1)", $rows[0][1]);
        $this->assertSame("'+Formula", $rows[1][1]);
        $this->assertSame("'-Formula", $rows[2][1]);
        $this->assertSame("'@Formula", $rows[3][1]);
        $this->assertSame("'\t=Formula", $rows[4][1]);
        $this->assertSame('Ordinary text', $rows[5][1]);
        $this->assertStringNotContainsString('SECRET', $csv);
    }

    public function test_each_export_uses_its_module_permission_rather_than_operations_permission(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user, ['role' => OrganizationRole::Viewer->value]);
        $deniedAbility = null;
        Gate::before(function (User $actor, string $ability) use (&$deniedAbility): ?bool {
            return $ability === $deniedAbility ? false : null;
        });
        foreach (['maintenance' => 'viewOperations', 'inventory' => 'viewInventory', 'leads' => 'viewCrm', 'receivables' => 'viewFinance'] as $report => $ability) {
            $deniedAbility = $ability;
            $this->actingAs($user)->get(route('reports.export', ['report' => $report]))->assertForbidden();
            $deniedAbility = null;
            $this->get(route('reports.export', ['report' => $report]))->assertOk();
        }
        $deniedAbility = 'viewOperations';
        $this->get(route('reports.export', ['report' => 'receivables']))->assertOk();
        $this->get(route('reports.export', ['report' => 'unknown']))->assertNotFound();
    }

    public function test_lead_export_preserves_assignee_visibility(): void
    {
        $organization = Organization::factory()->create();
        $viewer = User::factory()->create(['current_organization_id' => $organization->id]);
        $other = User::factory()->create();
        $organization->users()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
        $organization->users()->attach($other, ['role' => OrganizationRole::Member->value]);
        CrmLead::create(['organization_id' => $organization->id, 'first_name' => '=Visible', 'last_name' => 'Local', 'assigned_to' => $viewer->id]);
        CrmLead::create(['organization_id' => $organization->id, 'first_name' => 'Hidden', 'last_name' => 'Other', 'assigned_to' => $other->id]);
        $csv = $this->actingAs($viewer)->get(route('reports.export', ['report' => 'leads']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=Visible", $csv);
        $this->assertStringNotContainsString('Hidden', $csv);
    }

    public function test_nonmember_cannot_export_any_report(): void
    {
        $organization = Organization::factory()->create();
        $outsider = User::factory()->create(['current_organization_id' => $organization->id]);
        foreach (['maintenance', 'inventory', 'leads', 'receivables'] as $report) {
            $this->actingAs($outsider)->get(route('reports.export', ['report' => $report]))->assertForbidden();
        }
    }
}
