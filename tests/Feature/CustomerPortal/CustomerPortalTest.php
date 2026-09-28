<?php

namespace Tests\Feature\CustomerPortal;

use App\Domain\CustomerPortal\Actions\ManagePortalAccess;
use App\Domain\CustomerPortal\Models\PortalGrant;
use App\Domain\CustomerPortal\Models\PortalInvitation;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Actions\SubmitCustomerMaintenance;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $customer;

    private Tenant $tenant;

    private Property $property;

    private Lease $lease;

    private CrmContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);
        $this->customer = User::factory()->create(['current_organization_id' => null]);
        $this->tenant = Tenant::create(['organization_id' => $this->organization->id, 'name' => 'Tenant']);
        $this->property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Property', 'type' => 'residential']);
        $unit = Unit::create(['organization_id' => $this->organization->id, 'property_id' => $this->property->id, 'number' => '101', 'type' => 'apartment']);
        $this->contact = CrmContact::create(['organization_id' => $this->organization->id, 'first_name' => 'Customer', 'last_name' => 'Portal']);
        $this->lease = Lease::create(['organization_id' => $this->organization->id, 'unit_id' => $unit->id, 'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'reference' => 'LEASE-PORTAL', 'status' => 'active', 'starts_on' => today()->subMonth()->format('Y-m-d'), 'ends_on' => today()->addYear()->format('Y-m-d'), 'rent_amount' => '1000']);
    }

    /** @return array{invitation: PortalInvitation, token: string} */
    private function invitation(string $role = 'tenant', ?int $entityId = null): array
    {
        return app(ManagePortalAccess::class)->invite($this->organization, $this->owner, ['email' => $this->customer->email, 'role' => $role, 'entity_id' => $entityId ?? $this->tenant->id]);
    }

    private function grant(): PortalGrant
    {
        return app(ManagePortalAccess::class)->accept($this->customer, $this->invitation()['token']);
    }

    public function test_invitation_requires_matching_verified_email_and_never_grants_employee_access(): void
    {
        $invite = $this->invitation();
        $this->assertSame(hash('sha256', $invite['token']), $invite['invitation']->token_hash);
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('portal.accept', $invite['token']))->assertNotFound();
        $this->customer->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->customer)->post(route('portal.accept', $invite['token']))->assertRedirect(route('verification.notice'));
        $this->customer->forceFill(['email_verified_at' => now()])->save();
        $this->post(route('portal.accept', $invite['token']))->assertRedirect();
        $grant = PortalGrant::sole();
        $this->post(route('portal.accept', $invite['token']))->assertRedirect(route('portal.show', $grant));
        $this->assertDatabaseCount('portal_grants', 1);
        $this->assertFalse($this->customer->belongsToOrganization($this->organization));
        $this->assertNull($this->customer->fresh()->current_organization_id);
        $this->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->component('portal/Overview')->where('leases.0.reference', 'LEASE-PORTAL')->has('leases', 1));
        $this->get(route('stock.index'))->assertNotFound();
    }

    public function test_expired_revoked_and_foreign_grants_are_denied_immediately(): void
    {
        $invite = $this->invitation();
        $invite['invitation']->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->customer)->post(route('portal.accept', $invite['token']))->assertNotFound();
        $grant = $this->grant();
        $this->actingAs(User::factory()->create())->get(route('portal.show', $grant))->assertNotFound();
        $this->actingAs($this->owner)->post(route('portal.revoke', $grant->portal_invitation_id), ['reason' => 'Access ended'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->get(route('portal.show', $grant))->assertNotFound();
        $this->post(route('portal.service', $grant), ['lease_id' => $this->lease->id, 'title' => 'Denied', 'priority' => 'medium', 'operation_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_tenant_reads_only_explicit_lease_invoices_and_submits_idempotent_scoped_requests(): void
    {
        $grant = $this->grant();
        $invoice = Invoice::create(['organization_id' => $this->organization->id, 'contact_id' => $this->contact->id, 'reference' => 'LINKED', 'status' => 'posted', 'total' => '100', 'subtotal' => '100', 'issued_on' => today()]);
        Invoice::create(['organization_id' => $this->organization->id, 'contact_id' => $this->contact->id, 'reference' => 'UNLINKED', 'status' => 'posted', 'total' => '900', 'issued_on' => today()]);
        app(ManagePortalAccess::class)->linkInvoice($this->organization, $this->owner, $this->lease->id, $invoice->id);
        $this->actingAs($this->customer)->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.reference', 'LINKED')->where('invoices.data.0.outstanding', '100.00'));
        $input = ['lease_id' => $this->lease->id, 'title' => 'Water leak', 'description' => 'Kitchen sink', 'priority' => 'high', 'operation_key' => (string) Str::uuid()];
        $this->post(route('portal.service', $grant), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('portal.service', $grant), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('portal.service', $grant), [...$input, 'title' => 'Changed'])->assertSessionHasErrors('operation_key');
        $job = MaintenanceRequest::sole();
        $this->assertSame($this->property->id, $job->property_id);
        $this->assertTrue($job->requires_manager_confirmation);
        $this->assertNull($job->assigned_to);
        $this->assertDatabaseCount('portal_service_requests', 1);
        $this->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->has('serviceRequests.data', 1)->where('serviceRequests.data.0.title', 'Water leak'));
        $this->get(route('maintenance.job-card', $job))->assertNotFound();
        $this->post(route('portal.link-invoice'), ['lease_id' => $this->lease->id, 'invoice_id' => $invoice->id])->assertNotFound();
        $this->lease->update(['status' => 'ended']);
        $this->post(route('portal.service', $grant), [...$input, 'operation_key' => (string) Str::uuid()])->assertSessionHasErrors('lease_id');
        $this->assertDatabaseCount('maintenance_requests', 1);
    }

    public function test_invoice_unsharing_and_foreign_lease_requests_are_protected(): void
    {
        $grant = $this->grant();
        $invoice = Invoice::create(['organization_id' => $this->organization->id, 'contact_id' => $this->contact->id, 'reference' => 'LINKED', 'status' => 'posted', 'total' => '100', 'issued_on' => today()]);
        $link = app(ManagePortalAccess::class)->linkInvoice($this->organization, $this->owner, $this->lease->id, $invoice->id);
        $this->actingAs($this->owner)->post(route('portal.revoke-invoice', $link), ['reason' => 'Shared in error'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
        $this->assertDatabaseHas('portal_lease_invoices', ['id' => $link->id, 'revocation_reason' => 'Shared in error']);
        $otherTenant = Tenant::create(['organization_id' => $this->organization->id, 'name' => 'Other tenant']);
        $this->lease->update(['tenant_id' => $otherTenant->id]);
        $this->post(route('portal.service', $grant), ['lease_id' => $this->lease->id, 'title' => 'Foreign lease', 'priority' => 'medium', 'operation_key' => (string) Str::uuid()])->assertNotFound();
        $this->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->has('leases', 0));
        $manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($manager, ['role' => OrganizationRole::Manager->value]);
        $this->actingAs($manager)->post(route('portal.invite'), ['email' => $this->customer->email, 'role' => 'tenant', 'entity_id' => $this->tenant->id])->assertForbidden();
    }

    public function test_customer_request_audit_failure_rolls_back_job_and_request(): void
    {
        $grant = $this->grant();
        $audit = \Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(SubmitCustomerMaintenance::class)->handle($this->customer, $grant, ['lease_id' => $this->lease->id, 'title' => 'Leak', 'priority' => 'medium', 'operation_key' => (string) Str::uuid()]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('portal_service_requests', 0);
        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    public function test_landlord_scope_follows_ownership_and_cannot_submit_tenant_requests(): void
    {
        $landlord = Owner::create(['organization_id' => $this->organization->id, 'name' => 'Landlord']);
        $landlord->properties()->attach($this->property, ['ownership_share' => '100']);
        $grant = app(ManagePortalAccess::class)->accept($this->customer, $this->invitation('landlord', $landlord->id)['token']);
        MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $this->property->id, 'reference' => 'VISIBLE', 'title' => 'Repair', 'description' => 'Private internal detail', 'actual_cost' => '999']);
        $other = Property::create(['organization_id' => $this->organization->id, 'name' => 'Other landlord', 'type' => 'residential']);
        MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $other->id, 'reference' => 'HIDDEN', 'title' => 'Hidden']);
        $this->actingAs($this->customer)->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->has('properties', 1)->has('serviceSummaries.data', 1)->where('serviceSummaries.data.0.reference', 'VISIBLE')->missing('serviceSummaries.data.0.description')->missing('serviceSummaries.data.0.actual_cost')->where('statement.owner.id', $landlord->id)->etc());
        $this->post(route('portal.service', $grant), ['lease_id' => $this->lease->id, 'title' => 'Denied', 'priority' => 'medium', 'operation_key' => (string) Str::uuid()])->assertForbidden();
        $landlord->properties()->detach($this->property);
        $this->get(route('portal.show', $grant))->assertInertia(fn (Assert $page) => $page->has('properties', 0)->has('serviceSummaries.data', 0));
    }
}
