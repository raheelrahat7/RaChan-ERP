<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StageNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    public function test_each_committed_stage_entry_notifies_the_assignee_once_and_is_audited(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $url = route('crm.pipeline-stages.assignee-notification', [$pipeline, $target]);
        $this->actingAs($owner)->put($url, ['enabled' => true])->assertRedirect();
        $this->assertTrue($target->fresh()->notify_assignee_on_entry);
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Avery', 'last_name' => 'Lead']);
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $agent->id])->assertRedirect();
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $notification = OrganizationNotification::where('organization_id', $org->id)->where('user_id', $agent->id)->where('category', 'crm_stage_entry')->sole();
        $this->assertSame(1, $notification->count);
        $this->assertSame('/crm/leads', $notification->href);
        $history = $lead->history()->latest('id')->first();
        $this->assertSame('crm_stage_entry:'.$history->id, $notification->event_key);
        $this->assertSame($agent->id, $history->snapshot['assigned_to']);
        $this->assertTrue($history->snapshot['notify_assignee_on_entry']);
        event(new LeadStageChanged($history));
        $this->assertDatabaseCount('organization_notifications', 1);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'event' => 'crm.stage_notification.created', 'subject_id' => $notification->id]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'crm.stage_notification.created')->count());
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $initial->id, 'expected_stage_id' => $target->id]);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertDatabaseCount('organization_notifications', 2);
        $this->actingAs($owner)->put($url, ['enabled' => false])->assertRedirect();
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $initial->id, 'expected_stage_id' => $target->id]);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertDatabaseCount('organization_notifications', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'crm.stage.assignee_notification_updated', 'subject_id' => $target->id]);
    }

    public function test_rollback_unassigned_and_changed_recipient_do_not_deliver(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $target = $pipeline->stages()->where('name', 'Qualified')->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.assignee-notification', [$pipeline, $target]), ['enabled' => true])->assertRedirect();
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Test', 'last_name' => 'Lead']);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $this->assertDatabaseCount('organization_notifications', 0);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $initial->id, 'expected_stage_id' => $target->id]);
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => $agent->id])->assertRedirect();
        try {
            DB::transaction(function () use ($org, $owner, $lead, $target, $initial): void {
                app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame($initial->id, $lead->fresh()->current_stage_id);
        $this->assertDatabaseCount('organization_notifications', 0);
        app(ManageLeadPipeline::class)->move($org, $owner, $lead, ['stage_id' => $target->id, 'expected_stage_id' => $initial->id]);
        $history = $lead->history()->latest('id')->first();
        $this->assertDatabaseCount('organization_notifications', 1);
        $this->actingAs($owner)->put(route('crm.leads.assignment', $lead), ['assigned_to' => null])->assertRedirect();
        event(new LeadStageChanged($history));
        $this->assertDatabaseCount('organization_notifications', 1);
    }

    public function test_only_pipeline_managers_can_configure_own_organization_stages(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $manager = $this->member($org, OrganizationRole::Manager);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $stage = $pipeline->stages()->first();
        $foreign = Organization::factory()->create();
        $foreignPipeline = Pipeline::where('organization_id', $foreign->id)->sole();
        $foreignStage = $foreignPipeline->stages()->first();
        $url = route('crm.pipeline-stages.assignee-notification', [$pipeline, $stage]);
        $this->actingAs($manager)->put($url, ['enabled' => true])->assertForbidden();
        $this->actingAs($owner)->put(route('crm.pipeline-stages.assignee-notification', [$foreignPipeline, $foreignStage]), ['enabled' => true])->assertNotFound();
        $this->put(route('crm.pipeline-stages.assignee-notification', [$pipeline, $foreignStage]), ['enabled' => true])->assertNotFound();
        $this->put($url, ['enabled' => 'bad'])->assertSessionHasErrors('enabled');
        $this->assertFalse($stage->fresh()->notify_assignee_on_entry);
    }

    public function test_creation_and_cross_pipeline_entry_use_the_same_notification_rule(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, OrganizationRole::Owner);
        $agent = $this->member($org, OrganizationRole::Member);
        $source = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $source->stages()->where('is_initial', true)->sole();
        $destination = Pipeline::create(['organization_id' => $org->id, 'name' => 'Second']);
        $target = $destination->stages()->create(['name' => 'Review', 'position' => 1, 'is_initial' => true]);
        $this->actingAs($owner)->put(route('crm.pipeline-stages.assignee-notification', [$source, $initial]), ['enabled' => true])->assertRedirect();
        $this->put(route('crm.pipeline-stages.assignee-notification', [$destination, $target]), ['enabled' => true])->assertRedirect();
        $lead = app(ManageLeadPipeline::class)->create($org, $owner, ['first_name' => 'Nora', 'last_name' => 'Prospect', 'assigned_to' => $agent->id]);
        $this->assertDatabaseCount('organization_notifications', 1);
        app(ManageLeadPipeline::class)->transfer($org, $owner, $lead, ['pipeline_id' => $destination->id, 'stage_id' => $target->id, 'expected_pipeline_id' => $source->id, 'expected_stage_id' => $initial->id]);
        $this->assertDatabaseCount('organization_notifications', 2);
        $this->assertSame(2, OrganizationNotification::where('organization_id', $org->id)->where('user_id', $agent->id)->count());
    }
}
