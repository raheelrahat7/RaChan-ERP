<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_select_only_supported_locales_and_preference_survives_a_new_session(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/locale', ['locale' => 'ar'])->assertRedirect();
        $this->assertSame('ar', $user->fresh()->locale);
        $this->get('/portal')->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));
        $this->get('/portal')->assertSee('dir="rtl"', false);
        $this->post('/locale', ['locale' => '../../private'])->assertSessionHasErrors('locale');
        $this->assertSame('ar', $user->fresh()->locale);
        $this->post('/locale', ['locale' => 'en'])->assertRedirect();
        $this->get('/portal')->assertSee('dir="ltr"', false);
    }

    public function test_guest_preference_is_session_scoped_and_validation_messages_are_localized(): void
    {
        $this->withoutVite();
        $this->post('/locale', ['locale' => 'ar'])->assertSessionHas('locale', 'ar');
        $this->get('/login')->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
        $this->post('/locale', [])->assertSessionHasErrors(['locale' => 'حقل اللغة مطلوب.']);
    }

    public function test_arabic_technician_completion_keeps_canonical_workflow_values_and_translates_errors(): void
    {
        $this->withoutVite();
        $org = Organization::factory()->create();
        $tech = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($tech, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Name', 'type' => 'commercial']);
        $job = MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'AR-1', 'title' => 'Name', 'status' => 'open', 'priority' => 'high', 'assigned_to' => $tech->id, 'requires_manager_confirmation' => false]);
        $task = $job->jobTasks()->create(['organization_id' => $org->id, 'created_by' => $tech->id, 'label' => 'Inspect equipment', 'is_required' => true]);
        $this->actingAs($tech)->post('/locale', ['locale' => 'ar'])->assertRedirect();
        $this->get('/maintenance/'.$job->id.'/job-card')->assertInertia(fn (Assert $page) => $page->where('locale', 'ar')->where('job.title', 'Name')->where('job.status', 'open'));
        $this->post(route('maintenance.job-card.finish', $job))->assertSessionHasErrors(['checklist' => 'أكمل جميع بنود قائمة التحقق المطلوبة أولاً.']);
        $this->put('/maintenance/'.$job->id.'/job-card/tasks/'.$task->id, ['complete' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.finish', $job))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $job->fresh()->status);
    }
}
