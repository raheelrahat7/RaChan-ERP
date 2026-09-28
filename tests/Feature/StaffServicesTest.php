<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffServicesTest extends TestCase
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

    public function test_staff_expiry_and_leave_are_private_and_approved_by_another_user(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $employee = $this->member($org, OrganizationRole::Viewer);
        $other = $this->member($org, OrganizationRole::Viewer);
        $this->actingAs($owner)->post(route('hr.staff.store'), ['user_id' => $employee->id, 'job_title' => 'Agent'])->assertRedirect();
        $staff = DB::table('hr_staff')->sole();
        $this->post(route('hr.documents.store', $staff->id), ['type' => 'emirates_id', 'expires_on' => now()->addDays(10)->toDateString(), 'reference_suffix' => '1234'])->assertRedirect();
        $this->actingAs($employee)->get(route('hr.index'))->assertInertia(fn (Assert $page) => $page
            ->where('staff.0.user_id', $employee->id)->where('documents.0.type', 'emirates_id')->etc());
        $this->actingAs($other)->get(route('hr.index'))->assertInertia(fn (Assert $page) => $page
            ->where('staff', [])->where('documents', [])->etc());
        $this->actingAs($employee)->post(route('hr.leave.store', $staff->id), ['starts_on' => now()->addDays(20)->toDateString(), 'ends_on' => now()->addDays(22)->toDateString(), 'type' => 'annual'])->assertRedirect();
        $leave = DB::table('hr_leave_requests')->sole();
        $this->actingAs($owner)->post(route('hr.leave.decide', $leave->id), ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('hr_leave_requests', ['id' => $leave->id, 'status' => 'approved', 'decided_by' => $owner->id]);
    }

    public function test_foreign_member_and_unprivileged_document_changes_are_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $foreign = $this->member(Organization::factory()->create(), OrganizationRole::Viewer);
        $this->actingAs($owner)->post(route('hr.staff.store'), ['user_id' => $foreign->id])->assertSessionHasErrors(['user_id']);
        $this->post(route('hr.staff.store'), ['user_id' => $viewer->id])->assertRedirect();
        $staff = DB::table('hr_staff')->sole();
        $this->actingAs($viewer)->post(route('hr.documents.store', $staff->id), ['type' => 'visa', 'expires_on' => now()->addMonth()->toDateString()])->assertForbidden();
        $this->assertDatabaseCount('hr_staff_documents', 0);
    }
}
