<?php

namespace Tests\Feature\Crm;

use App\Domain\Crm\Actions\ExecuteAutomationRule;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\AutomationRule;
use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\LeadImportBatch;
use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadBackendCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function member(Organization $org, OrganizationRole $role = OrganizationRole::Owner): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function lead(Organization $org, User $owner, array $extra = []): CrmLead
    {
        $this->actingAs($owner)->post(route('crm.leads.store'), ['first_name' => 'Sara', 'last_name' => 'Ali', ...$extra])->assertRedirect()->assertSessionHasNoErrors();

        return CrmLead::where('organization_id', $org->id)->latest('id')->firstOrFail();
    }

    private function rule(Organization $org, User $owner, array $extra = []): AutomationRule
    {
        $pipeline = Pipeline::where('organization_id', $org->id)->firstOrFail();
        $this->actingAs($owner)->post(route('crm.automation.store'), ['name' => 'Schedule a call', 'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->where('is_initial', true)->firstOrFail()->id,
            'trigger' => 'lead_created', 'action' => 'create_follow_up', 'activity_type' => 'call', 'due_days' => 1, ...$extra,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return AutomationRule::where('organization_id', $org->id)->latest('id')->firstOrFail();
    }

    public function test_csv_source_settings_decode_delimit_map_names_and_assign_to_a_member(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $agent = $this->member($org, OrganizationRole::Member);
        $csv = mb_convert_encoding("Name;Email;Unused\nDurand Élodie;elodie@example.test;\n", 'Windows-1252', 'UTF-8');
        $this->actingAs($owner)->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
            'encoding' => 'Windows-1252', 'delimiter' => 'semicolon', 'has_header' => true, 'skip_empty_columns' => true, 'name_format' => 'last_first',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $batch = LeadImportBatch::sole();
        $this->assertSame(['Name', 'Email'], $batch->headers);
        $this->post(route('crm.leads.import.commit', $batch), ['pipeline_id' => Pipeline::where('organization_id', $org->id)->sole()->id,
            'mapping' => ['Name' => 'full_name', 'Email' => 'email'], 'duplicate_mode' => 'skip', 'assigned_to' => $agent->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crm_leads', ['first_name' => 'Élodie', 'last_name' => 'Durand', 'assigned_to' => $agent->id]);
        $this->get(route('crm.leads.import.sample'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_headerless_tab_csv_keeps_the_first_record_and_reports_correct_row_numbers(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->actingAs($owner)->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "Sara\tAli\tbad-email\nAmina\tKhan\tamina@example.test\n"),
            'delimiter' => 'tab', 'has_header' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $batch = LeadImportBatch::sole();
        $this->assertCount(2, $batch->rows);
        $this->post(route('crm.leads.import.commit', $batch), ['pipeline_id' => Pipeline::where('organization_id', $org->id)->sole()->id,
            'mapping' => ['Column 1' => 'first_name', 'Column 2' => 'last_name', 'Column 3' => 'email'], 'duplicate_mode' => 'skip',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $batch->refresh()->errors[0]['row']);
        $this->assertDatabaseHas('crm_leads', ['first_name' => 'Amina']);
    }

    public function test_a_failed_custom_field_row_does_not_reserve_the_duplicate_key(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Category', 'key' => 'category', 'type' => 'single_select', 'options' => ['Direct']])->assertRedirect();
        $this->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "First,Last,Email,Category\nSara,Ali,sara@example.test,Invalid\nSara,Ali,sara@example.test,Direct\n")])->assertRedirect();
        $batch = LeadImportBatch::sole();
        $this->post(route('crm.leads.import.commit', $batch), ['pipeline_id' => Pipeline::where('organization_id', $org->id)->sole()->id, 'duplicate_mode' => 'skip',
            'mapping' => ['First' => 'first_name', 'Last' => 'last_name', 'Email' => 'email', 'Category' => 'custom:category'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(['created' => 1, 'skipped_duplicates' => 0, 'failed' => 1], $batch->refresh()->summary);
    }

    public function test_duplicate_control_checks_normalized_phone_even_when_email_differs(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->lead($org, $owner, ['email' => 'OLD@example.test', 'phone' => '+971 50-123']);
        $this->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "First,Last,Email,Phone\nSara,Ali,new@example.test,97150123\nSara,Ali,old@example.test,999\n")])->assertRedirect();
        $batch = LeadImportBatch::sole();
        $this->post(route('crm.leads.import.commit', $batch), ['pipeline_id' => Pipeline::where('organization_id', $org->id)->sole()->id, 'duplicate_mode' => 'skip',
            'mapping' => ['First' => 'first_name', 'Last' => 'last_name', 'Email' => 'email', 'Phone' => 'phone'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $batch->refresh()->summary['skipped_duplicates']);
        $this->assertSame(1, CrmLead::count());
    }

    public function test_import_rejects_foreign_assignees_expired_previews_and_invalid_encoding(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $foreign = User::factory()->create();
        $this->actingAs($owner)->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "First,Last\nSara,Ali\n")])->assertRedirect();
        $batch = LeadImportBatch::sole();
        $input = ['pipeline_id' => Pipeline::where('organization_id', $org->id)->sole()->id, 'duplicate_mode' => 'allow', 'mapping' => ['First' => 'first_name', 'Last' => 'last_name']];
        $this->post(route('crm.leads.import.commit', $batch), [...$input, 'assigned_to' => $foreign->id])->assertSessionHasErrors('assignee_id');
        $batch->update(['expires_at' => now()->subMinute()]);
        $this->post(route('crm.leads.import.commit', $batch), $input)->assertSessionHasErrors('batch');
        $this->assertDatabaseCount('crm_leads', 0);
        $this->post(route('crm.leads.import.preview'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', "Name\n\xE9\n")])->assertSessionHasErrors('file');
    }

    public function test_combined_activity_filters_must_match_one_activity_and_honor_not_equals(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $lead = $this->lead($org, $owner);
        $lead->activities()->create(['organization_id' => $org->id, 'type' => 'call', 'completed_at' => now()]);
        $lead->activities()->create(['organization_id' => $org->id, 'type' => 'task']);
        $filters = [['field' => 'activity_type', 'operator' => 'equals', 'value' => 'call'], ['field' => 'activity_status', 'operator' => 'equals', 'value' => 'open']];
        $this->get(route('crm.leads.index', ['filters' => $filters]))->assertInertia(fn (Assert $page) => $page->has('leads', 0));
        $filters[1]['operator'] = 'not_equals';
        $this->get(route('crm.leads.index', ['filters' => $filters]))->assertInertia(fn (Assert $page) => $page->has('leads', 1));
    }

    public function test_full_name_date_and_stage_history_filters_use_correct_semantics(): void
    {
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $owner = $this->member($org);
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(22, 0));
        $lead = $this->lead($org, $owner);
        $this->get(route('crm.leads.index', ['q' => 'Sara Ali']))->assertInertia(fn (Assert $page) => $page->has('leads', 1));
        $filters = [['field' => 'lead_name', 'operator' => 'equals', 'value' => 'Sara Ali'], ['field' => 'created_at', 'operator' => 'equals', 'value' => '2026-10-05']];
        $this->get(route('crm.leads.index', ['filters' => $filters]))->assertInertia(fn (Assert $page) => $page->has('leads', 1));
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'stage_history', 'operator' => 'not_equals', 'value' => $lead->current_stage_id]]]))->assertInertia(fn (Assert $page) => $page->has('leads', 0));
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'id', 'operator' => 'gte', 'value' => 'bad']]]))->assertSessionHasErrors('filters.0');
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'created_at', 'operator' => 'equals', 'value' => 'bad']]]))->assertSessionHasErrors('filters.0');
        $this->travelBack();
    }

    public function test_custom_field_edits_and_clears_are_audited_without_copying_values(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Reference', 'key' => 'reference', 'type' => 'text'])->assertRedirect();
        $lead = $this->lead($org, $owner, ['custom_fields' => ['reference' => 'SECRET-ONE']]);
        foreach (['SECRET-TWO', null] as $value) {
            $this->put(route('crm.leads.details', $lead), ['first_name' => 'Sara', 'last_name' => 'Ali', 'custom_fields' => ['reference' => $value]])->assertRedirect()->assertSessionHasNoErrors();
        }
        $events = AuditLog::where('event', 'crm.lead.custom_field_changed')->get();
        $this->assertCount(3, $events);
        $this->assertStringNotContainsString('SECRET', $events->toJson());
    }

    public function test_activity_board_is_filtered_scoped_and_uses_local_due_boundaries(): void
    {
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $owner = $this->member($org);
        $manager = $this->member($org, OrganizationRole::Manager);
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(18, 30)); // 23:30 locally
        $overdue = $this->lead($org, $owner, ['assigned_to' => $manager->id, 'city' => 'Dubai']);
        $overdue->activities()->create(['organization_id' => $org->id, 'type' => 'call', 'due_at' => now()->subHour()]);
        $tomorrow = $this->lead($org, $owner, ['assigned_to' => $manager->id, 'city' => 'Dubai']);
        $tomorrow->activities()->create(['organization_id' => $org->id, 'type' => 'task', 'due_at' => now()->addHour()]);
        $this->lead($org, $owner, ['city' => 'Dubai']); // invisible to manager
        $this->lead($org, $owner, ['assigned_to' => $manager->id, 'city' => 'Abu Dhabi']);
        $response = $this->actingAs($manager)->getJson(route('crm.activities.index', ['filters' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Dubai']]]))->assertOk();
        $response->assertJsonPath('board.timezone', 'Asia/Karachi')->assertJsonPath('board.lanes.0.total', 1)
            ->assertJsonPath('board.lanes.1.total', 0)->assertJsonPath('board.lanes.2.total', 1)->assertJsonPath('board.lanes.4.total', 0)
            ->assertJsonPath('activities.total', 2);
        $this->travelBack();
    }

    public function test_activity_rescheduling_is_local_audited_and_rejects_stale_or_completed_edits(): void
    {
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $owner = $this->member($org);
        $lead = $this->lead($org, $owner);
        $this->post(route('crm.activities.store'), ['lead_id' => $lead->id, 'type' => 'call', 'due_at' => '2026-10-06T09:00'])->assertRedirect();
        $activity = $lead->activities()->sole();
        $this->assertSame('2026-10-06 04:00:00', $activity->due_at->format('Y-m-d H:i:s'));
        $stamp = $activity->updated_at->toIso8601String();
        $this->travel(2)->seconds();
        $this->put(route('crm.activities.update', $activity), ['expected_updated_at' => $stamp, 'due_at' => '2026-10-07T10:00', 'notes' => 'PRIVATE-NOTE'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-10-07 05:00:00', $activity->refresh()->due_at->format('Y-m-d H:i:s'));
        $this->put(route('crm.activities.update', $activity), ['expected_updated_at' => $stamp, 'notes' => 'Stale'])->assertSessionHasErrors('activity');
        $this->assertStringNotContainsString('PRIVATE-NOTE', AuditLog::where('event', 'crm.activity.updated')->sole()->toJson());
        $this->post(route('crm.activities.complete', $activity))->assertRedirect();
        $this->put(route('crm.activities.update', $activity), ['expected_updated_at' => $activity->fresh()->updated_at->toIso8601String(), 'notes' => 'Changed'])->assertStatus(422);
        $this->travelBack();
    }

    public function test_history_pagination_retrieves_older_events_and_filters_by_event(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $lead = $this->lead($org, $owner);
        for ($i = 0; $i < 105; $i++) {
            AuditLog::create(['organization_id' => $org->id, 'actor_id' => $owner->id, 'event' => 'crm.lead.details_updated', 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id, 'properties' => ['changed_fields' => ['city']]]);
        }
        $this->get(route('crm.leads.show', [$lead, 'history_event' => 'crm.lead.details_updated', 'history_page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('timeline', 5)->where('historyPagination.total', 105)->where('historyPagination.page', 2));
    }

    public function test_delayed_automation_runs_once_and_creates_the_selected_activity_type(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->rule($org, $owner, ['delay_minutes' => 30]);
        $lead = $this->lead($org, $owner);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'pending']);
        $this->travel(31)->minutes();
        $this->artisan('crm:run-due-automation')->assertSuccessful();
        $this->artisan('crm:run-due-automation')->assertSuccessful();
        event(new LeadStageChanged(LeadStageHistory::where('lead_id', $lead->id)->sole()));
        $this->assertDatabaseCount('crm_activities', 1);
        $this->assertDatabaseHas('crm_activities', ['subject_id' => $lead->id, 'type' => 'call']);
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'completed']);
        $this->travelBack();
    }

    public function test_changed_or_disabled_rules_do_not_execute_pending_work(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $rule = $this->rule($org, $owner, ['delay_minutes' => 30]);
        $this->lead($org, $owner);
        $rule->update(['name' => 'Changed action']);
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'skipped_changed']);
        $this->lead($org, $owner);
        $rule->update(['active' => false]);
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'skipped_disabled']);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->travelBack();
    }

    public function test_a_lead_that_leaves_and_returns_to_a_stage_invalidates_old_automation(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->rule($org, $owner, ['delay_minutes' => 30]);
        $lead = $this->lead($org, $owner);
        $initial = $lead->current_stage_id;
        $other = $lead->pipeline->stages()->where('type', 'normal')->whereKeyNot($initial)->firstOrFail();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $other->id, 'expected_stage_id' => $initial])->assertRedirect();
        $this->put(route('crm.leads.stage', $lead), ['stage_id' => $initial, 'expected_stage_id' => $other->id])->assertRedirect();
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'skipped_stale']);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->travelBack();
    }

    public function test_automatic_stage_changes_preserve_history_and_do_not_chain_rules(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $target = $pipeline->stages()->where('type', 'normal')->whereKeyNot($initial->id)->firstOrFail();
        $this->rule($org, $owner, ['action' => 'change_stage', 'target_stage_id' => $target->id, 'delay_minutes' => 30]);
        $this->rule($org, $owner, ['name' => 'Do not chain', 'trigger' => 'stage_entered', 'stage_id' => $target->id, 'action' => 'change_stage', 'target_stage_id' => $initial->id]);
        $lead = $this->lead($org, $owner);
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertSame($target->id, $lead->refresh()->current_stage_id);
        $this->assertSame(2, $lead->history()->count());
        $this->assertDatabaseCount('crm_automation_executions', 1);
        $this->assertNotNull($lead->history()->latest('id')->firstOrFail()->snapshot['automation_rule_id']);
        $this->travelBack();
    }

    public function test_manager_notifications_respect_visibility_and_assignee_changes_cancel_old_delivery(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $manager = $this->member($org, OrganizationRole::Manager);
        $otherManager = $this->member($org, OrganizationRole::Manager);
        $this->rule($org, $owner, ['action' => 'notify_managers']);
        $lead = $this->lead($org, $owner, ['assigned_to' => $manager->id]);
        $this->assertDatabaseHas('organization_notifications', ['user_id' => $owner->id, 'category' => 'crm_automation']);
        $this->assertDatabaseHas('organization_notifications', ['user_id' => $manager->id, 'category' => 'crm_automation']);
        $this->assertDatabaseMissing('organization_notifications', ['user_id' => $otherManager->id, 'category' => 'crm_automation']);
        $this->rule($org, $owner, ['action' => 'notify_assignee', 'delay_minutes' => 30]);
        $delayed = $this->lead($org, $owner, ['assigned_to' => $manager->id]);
        $this->put(route('crm.leads.assignment', $delayed), ['assigned_to' => $otherManager->id])->assertRedirect();
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'skipped_recipient']);
        $this->travelBack();
    }

    public function test_filter_preferences_are_private_encrypted_and_hide_revoked_fields(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $viewer = $this->member($org, OrganizationRole::Viewer);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Private code', 'key' => 'private_code', 'type' => 'text'])->assertRedirect();
        $this->postJson(route('crm.leads.preferences.store'), [
            'selected_field_keys' => ['lead_name', 'custom:private_code'], 'name' => 'My saved search',
            'state' => ['q' => 'PRIVATE-SEARCH', 'filters' => [['field' => 'custom:private_code', 'operator' => 'equals', 'value' => 'PRIVATE-CODE']]],
        ])->assertOk()->assertJsonCount(1, 'presets')->assertJsonPath('selected_field_keys.1', 'custom:private_code');
        $stored = DB::table('crm_lead_preferences')->sole();
        $this->assertStringNotContainsString('PRIVATE-SEARCH', $stored->presets);
        $this->actingAs($viewer)->getJson(route('crm.leads.preferences.index'))->assertOk()->assertJsonPath('presets', []);
        $this->actingAs($owner)->delete(route('crm.custom-fields.archive', CustomField::sole()))->assertRedirect();
        $this->getJson(route('crm.leads.preferences.index'))->assertOk()->assertJsonPath('presets', [])->assertJsonPath('selected_field_keys', ['lead_name']);
        $this->postJson(route('crm.leads.preferences.store'), ['selected_field_keys' => ['custom:private_code']])->assertUnprocessable();
    }

    public function test_saved_filter_deletion_is_private_and_foreign_pipeline_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $other = $this->member($org, OrganizationRole::Owner);
        $response = $this->actingAs($owner)->postJson(route('crm.leads.preferences.store'), ['name' => 'Search', 'state' => ['q' => 'Sara']])->assertOk();
        $id = $response->json('presets.0.id');
        $this->actingAs($other)->deleteJson(route('crm.leads.preferences.destroy', $id))->assertNotFound();
        $foreign = Organization::factory()->create();
        $this->actingAs($owner)->postJson(route('crm.leads.preferences.store'), ['name' => 'Foreign', 'state' => ['pipeline_id' => Pipeline::where('organization_id', $foreign->id)->sole()->id]])->assertUnprocessable();
        $this->deleteJson(route('crm.leads.preferences.destroy', $id))->assertOk()->assertJsonPath('presets', []);
    }

    public function test_automatic_stage_change_enforces_current_entry_requirements(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $pipeline = Pipeline::where('organization_id', $org->id)->sole();
        $initial = $pipeline->stages()->where('is_initial', true)->sole();
        $target = $pipeline->stages()->where('type', 'normal')->whereKeyNot($initial->id)->firstOrFail();
        $target->update(['required_fields' => ['email']]);
        $this->rule($org, $owner, ['action' => 'change_stage', 'target_stage_id' => $target->id, 'delay_minutes' => 30]);
        $lead = $this->lead($org, $owner);
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertSame($initial->id, $lead->refresh()->current_stage_id);
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'blocked']);
        $this->assertSame(1, $lead->history()->count());
        $this->travelBack();
    }

    public function test_revoked_automation_authority_and_converted_leads_cancel_pending_work(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->rule($org, $owner, ['delay_minutes' => 30]);
        $lead = $this->lead($org, $owner);
        $this->post(route('crm.leads.convert', $lead))->assertRedirect();
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'skipped_stale']);
        $this->lead($org, $owner);
        $org->users()->updateExistingPivot($owner->id, ['role' => OrganizationRole::Member->value]);
        $this->travel(31)->minutes();
        app(ExecuteAutomationRule::class)->due();
        $this->assertDatabaseHas('crm_automation_executions', ['outcome' => 'skipped_disabled']);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->travelBack();
    }

    public function test_multi_select_filters_match_complete_options_and_checkbox_false(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $this->actingAs($owner)->post(route('crm.custom-fields.store'), ['name' => 'Tags', 'key' => 'tags', 'type' => 'multi_select', 'options' => ['Direct', 'Direct sale', 'Rental']])->assertRedirect();
        $this->post(route('crm.custom-fields.store'), ['name' => 'Contacted', 'key' => 'contacted', 'type' => 'checkbox'])->assertRedirect();
        $this->lead($org, $owner, ['custom_fields' => ['tags' => ['Direct sale', 'Rental'], 'contacted' => false]]);
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'custom:tags', 'operator' => 'equals', 'value' => 'Direct']]]))->assertInertia(fn (Assert $page) => $page->has('leads', 0));
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'custom:tags', 'operator' => 'contains', 'value' => 'Rental'], ['field' => 'custom:contacted', 'operator' => 'equals', 'value' => false]]]))->assertInertia(fn (Assert $page) => $page->has('leads', 1));
        $this->get(route('crm.leads.index', ['filters' => [['field' => 'custom:contacted', 'operator' => 'equals', 'value' => 'false'], ['field' => 'has_email', 'operator' => 'equals', 'value' => 'false']]]))->assertInertia(fn (Assert $page) => $page->has('leads', 1));
    }
}
