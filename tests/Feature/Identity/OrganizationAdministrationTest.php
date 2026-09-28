<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_invite_and_assign_a_role(): void
    {
        $organization = Organization::factory()->create();
        $administrator = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($administrator, ['role' => OrganizationRole::Administrator->value]);

        $this->actingAs($administrator)->post(route('organization.invitations.store'), [
            'email' => 'new.member@example.com',
            'role' => OrganizationRole::Member->value,
        ])->assertRedirect();

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'new.member@example.com',
            'role' => OrganizationRole::Member->value,
        ]);
    }

    public function test_a_member_cannot_manage_another_tenant(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $member = User::factory()->create(['current_organization_id' => $organization->id]);
        $otherMember = User::factory()->create();
        $organization->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $otherOrganization->users()->attach($otherMember, ['role' => OrganizationRole::Member->value]);

        $this->actingAs($member)->put(route('organization.members.update', $otherMember), [
            'role' => OrganizationRole::Viewer->value,
        ])->assertNotFound();
    }

    public function test_an_invited_user_can_accept_a_tokenized_invitation(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $organization->id,
            'email' => $invitedUser->email,
            'role' => OrganizationRole::Manager->value,
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($invitedUser)
            ->post(route('organization.invitations.accept', $invitation))
            ->assertRedirect(route('organization.edit'));

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $invitedUser->id,
            'role' => OrganizationRole::Manager->value,
        ]);
    }
}
