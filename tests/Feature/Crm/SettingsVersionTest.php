<?php

namespace Tests\Feature\Crm;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsVersionTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_calendar_rejects_missing_and_stale_versions_without_overwriting(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($this->member($org));
        $data = ['working_days' => [1 => ['start' => '09:00', 'end' => '17:00']], 'holidays' => []];
        $this->putJson('/crm/settings/calendar', $data)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/crm/settings/calendar', [...$data, 'expected_version' => 0])->assertOk()->assertJsonPath('version', 1)->assertJsonPath('permissions.edit', true);
        $this->putJson('/crm/settings/calendar', [...$data, 'expected_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/crm/settings/calendar', [...$data, 'holidays' => ['2026-12-02'], 'expected_version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/crm/settings/calendar', [...$data, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->getJson('/crm/settings/calendar')->assertOk()->assertJsonPath('calendar.version', 2)->assertJsonPath('calendar.holidays.0', '2026-12-02')->assertJsonPath('calendar.permissions.edit', true);

        $this->actingAs($this->member($org, OrganizationRole::Member))->putJson('/crm/settings/calendar', [...$data, 'expected_version' => 2])->assertForbidden();
        $other = Organization::factory()->create();
        $this->actingAs($this->member($other))->getJson('/crm/settings/calendar')->assertOk()->assertJsonPath('calendar', null);
        $this->putJson('/crm/settings/calendar', [...$data, 'expected_version' => 0])->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseHas('crm_working_calendars', ['organization_id' => $org->id, 'version' => 2]);
    }

    public function test_provider_profiles_are_versioned_scoped_and_remain_inactive(): void
    {
        $org = Organization::factory()->create();
        $this->actingAs($this->member($org));
        $url = '/organization/reference-settings/providers';
        $data = ['name' => 'Email profile', 'capability' => 'email', 'provider' => null, 'active' => false, 'settings' => ['label' => 'Sales']];
        $id = $this->postJson($url, $data)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('permissions.edit', true)->json('id');
        $this->putJson($url.'/'.$id, $data)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson($url.'/'.$id, [...$data, 'name' => 'Updated profile', 'expected_version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url.'/'.$id, [...$data, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson($url.'/'.$id, [...$data, 'active' => true, 'expected_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('active');
        $this->getJson($url)->assertOk()->assertJsonPath('records.0.version', 2)->assertJsonPath('records.0.name', 'Updated profile')->assertJsonPath('records.0.permissions.edit', true)->assertJsonPath('deliveryEnabled', false);
        $this->actingAs($this->member($org, OrganizationRole::Member))->putJson($url.'/'.$id, [...$data, 'expected_version' => 2])->assertForbidden();
        $this->actingAs($this->member(Organization::factory()->create()))->putJson($url.'/'.$id, [...$data, 'expected_version' => 2])->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'records');
        $this->assertDatabaseHas('organization_provider_profiles', ['id' => $id, 'version' => 2, 'active' => false]);
    }
}
