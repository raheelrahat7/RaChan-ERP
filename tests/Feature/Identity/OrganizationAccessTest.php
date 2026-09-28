<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateOrganizationForUser;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_organization_has_an_owner_as_its_current_tenant(): void
    {
        $user = User::factory()->create();

        $organization = app(CreateOrganizationForUser::class)->handle($user);

        $this->assertTrue($user->fresh()->currentOrganization->is($organization));
        $this->assertSame(OrganizationRole::Owner->value, $organization->users()->first()->pivot->role);
    }

    public function test_roles_grant_only_their_tenant_permissions(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $viewer = User::factory()->create();

        $organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $organization->users()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);

        $this->assertFalse($manager->hasOrganizationPermission($organization, OrganizationPermission::ManageMembers));
        $this->assertFalse($manager->hasOrganizationPermission($organization, OrganizationPermission::ManageOrganization));
        $this->assertTrue($viewer->hasOrganizationPermission($organization, OrganizationPermission::ViewDashboard));
        $this->assertFalse($viewer->hasOrganizationPermission($organization, OrganizationPermission::ManageMembers));
    }
}
