<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Platform\Actions\ManageReadTokens;
use App\Domain\Platform\Models\PersonalReadToken;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_read_only_scoped_revocable_and_expiring(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Workshop', 'type' => 'commercial']);
        $foreignProperty = Property::create(['organization_id' => $other->id, 'name' => 'Other workshop', 'type' => 'commercial']);
        $assigned = MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'ASSIGNED', 'title' => 'Private job', 'status' => 'open', 'assigned_to' => $user->id]);
        MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'HIDDEN', 'title' => 'Other job', 'status' => 'open']);
        MaintenanceRequest::create(['organization_id' => $other->id, 'property_id' => $foreignProperty->id, 'reference' => 'FOREIGN', 'title' => 'Other organization', 'status' => 'open', 'assigned_to' => $user->id]);
        $result = app(ManageReadTokens::class)->create($org, $user, ['name' => 'Mobile', 'days' => 1, 'abilities' => ['jobs:read']]);
        $secret = $result['secret'];
        $this->assertNotSame($secret, $result['record']->token_hash);
        $this->getJson('/api/v1/jobs')->assertUnauthorized();
        $this->withToken($secret)->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assigned->id)->assertJsonMissingPath('data.0.description');
        $this->withToken($secret)->getJson('/api/v1/properties')->assertForbidden();
        $this->withToken($secret)->getJson('/api/v1/jobs?per_page=101')->assertUnprocessable();
        $this->withToken($secret)->postJson('/api/v1/jobs', [])->assertStatus(405);
        $assigned->update(['assigned_to' => null]);
        $this->withToken($secret)->getJson('/api/v1/jobs')->assertJsonCount(0, 'data');
        $org->users()->detach($user);
        $this->withToken($secret)->getJson('/api/v1/jobs')->assertUnauthorized();
        $org->users()->attach($user, ['role' => OrganizationRole::Member->value]);
        $this->travel(2)->days();
        $this->withToken($secret)->getJson('/api/v1/jobs')->assertUnauthorized();
        $this->travelBack();
        app(ManageReadTokens::class)->revoke($org, $user, $result['record']->id);
        $this->withToken($secret)->getJson('/api/v1/jobs')->assertUnauthorized();
        $this->assertNotNull(PersonalReadToken::sole()->revoked_at);
    }

    public function test_management_cannot_revoke_another_users_token_and_never_exposes_the_hash(): void
    {
        $this->withoutVite();
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $org->id]);
        $member = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($owner, ['role' => OrganizationRole::Owner->value]);
        $org->users()->attach($member, ['role' => OrganizationRole::Member->value]);
        $token = app(ManageReadTokens::class)->create($org, $member, ['name' => 'My device', 'days' => 2, 'abilities' => ['jobs:read']]);
        $this->actingAs($owner)->delete(route('api-tokens.revoke', $token['record']->id))->assertNotFound();
        $this->actingAs($member)->get(route('api-tokens.index'))->assertOk()->assertDontSee($token['record']->token_hash);
        $member->forceFill(['email_verified_at' => null])->save();
        $this->withToken($token['secret'])->getJson('/api/v1/jobs')->assertUnauthorized();
    }

    public function test_crm_visibility_and_finance_organization_boundaries_apply_to_bearer_reads(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Member->value]);
        $mine = $org->leads()->create(['first_name' => 'Mine', 'last_name' => 'Lead', 'assigned_to' => $user->id, 'notes' => 'Internal notes']);
        $org->leads()->create(['first_name' => 'Hidden', 'last_name' => 'Lead']);
        $other->leads()->create(['first_name' => 'Foreign', 'last_name' => 'Lead', 'assigned_to' => $user->id]);
        Invoice::create(['organization_id' => $org->id, 'reference' => 'INV-LOCAL', 'status' => 'draft', 'subtotal' => '10.00', 'total' => '10.00', 'currency' => 'AED']);
        Invoice::create(['organization_id' => $other->id, 'reference' => 'INV-FOREIGN', 'status' => 'draft', 'subtotal' => '20.00', 'total' => '20.00', 'currency' => 'AED']);
        $secret = app(ManageReadTokens::class)->create($org, $user, ['name' => 'Reports', 'days' => 1, 'abilities' => ['leads:read', 'invoices:read']])['secret'];
        $this->withToken($secret)->getJson('/api/v1/leads')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)->assertJsonMissingPath('data.0.notes');
        $this->withToken($secret)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'INV-LOCAL')->assertJsonMissingPath('data.0.contact_id');
        $this->withToken($secret)->postJson('/api/v1/invoices', [])->assertStatus(405);
        $mine->update(['assigned_to' => null]);
        $this->withToken($secret)->getJson('/api/v1/leads')->assertJsonCount(0, 'data');
    }
}
