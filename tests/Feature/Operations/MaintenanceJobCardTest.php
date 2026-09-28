<?php

namespace Tests\Feature\Operations;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Actions\GenerateDailyNotifications;
use App\Domain\Operations\Actions\ManageJobCard;
use App\Domain\Operations\Models\JobCostLine;
use App\Domain\Operations\Models\JobTask;
use App\Domain\Operations\Services\JobCostAmount;
use App\Models\Document;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class MaintenanceJobCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $technician;

    private User $other;

    private MaintenanceRequest $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->organization = Organization::factory()->create();
        $this->manager = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->technician = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->other = User::factory()->create(['current_organization_id' => $this->organization->id]);
        $this->organization->users()->attach($this->manager, ['role' => OrganizationRole::Manager->value]);
        $this->organization->users()->attach($this->technician, ['role' => OrganizationRole::Member->value]);
        $this->organization->users()->attach($this->other, ['role' => OrganizationRole::Member->value]);
        $property = Property::create(['organization_id' => $this->organization->id, 'name' => 'Local property', 'type' => 'residential']);
        $this->job = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $property->id, 'reference' => 'MNT-JOB', 'title' => 'Repair service', 'assigned_to' => $this->technician->id, 'due_at' => now()->subDay(), 'actual_cost' => '200.00']);
    }

    public function test_assigned_technician_records_notes_checklist_costs_and_evidence_without_financial_posting(): void
    {
        $this->actingAs($this->manager)->post(route('maintenance.job-card.tasks', $this->job), ['label' => 'Test equipment', 'is_required' => true])->assertRedirect()->assertSessionHasNoErrors();
        $task = JobTask::sole();
        $this->actingAs($this->technician)->post(route('maintenance.job-card.notes', $this->job), ['note' => 'Repair completed and tested.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('maintenance.job-card.tasks.check', [$this->job, $task]), ['complete' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.costs', $this->job), ['category' => 'labor', 'description' => 'Repair labor', 'quantity' => '1.25', 'unit_rate' => '10.50'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.costs', $this->job), ['category' => 'material', 'description' => 'Parts', 'quantity' => '3', 'unit_rate' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('maintenance.job-card.evidence', $this->job), ['file' => $this->image('repair.png')])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('maintenance.job-card', $this->job))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/JobCard')->where('canManage', false)->where('canEdit', true)
            ->where('totals.labor', '13.13')->where('totals.material', '15.00')->where('totals.total', '28.13')
            ->where('job.actual_cost', '200.00')->where('job.job_notes.0.author.name', $this->technician->name)
            ->has('job.documents', 1)->missing('job.documents.0.path'));
        $this->assertSame($this->technician->id, $task->fresh()->completed_by);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('vendor_bills', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.job.cost_added', 'actor_id' => $this->technician->id, 'subject_id' => $this->job->id]);
    }

    public function test_technician_scope_applies_to_list_overview_csv_search_and_dashboard(): void
    {
        $hidden = MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $this->job->property_id, 'reference' => 'MNT-HIDDEN', 'title' => 'Hidden service', 'assigned_to' => $this->other->id, 'due_at' => now()->subDay()]);
        $this->actingAs($this->technician)->get(route('maintenance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.id', $this->job->id));
        $this->get(route('maintenance.job-card', $hidden))->assertNotFound();
        $this->get(route('operations.overview'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.total', 1)->where('summary.overdue', 1)->has('backlog.data', 1));
        $csv = $this->get(route('reports.export', ['report' => 'maintenance']))->assertOk()->streamedContent();
        $this->assertStringContainsString('MNT-JOB', $csv);
        $this->assertStringNotContainsString('MNT-HIDDEN', $csv);
        $csv = $this->get(route('operations.overview.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('MNT-HIDDEN', $csv);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('metrics.openMaintenance', 1)->where('metrics.overdueMaintenance', 1));
        $this->get('/search?q=MNT-')->assertOk()->assertInertia(fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'MNT-JOB'));
        $this->actingAs($this->manager)->get(route('maintenance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests', 2));
        $this->get(route('maintenance.job-card', $hidden))->assertOk();
    }

    public function test_reassignment_revokes_both_evidence_download_paths_and_old_technician_writes(): void
    {
        $this->actingAs($this->technician)->post(route('maintenance.job-card.evidence', $this->job), ['file' => $this->image('repair.png')])->assertRedirect();
        $document = Document::sole();
        $dedicated = route('maintenance.job-card.evidence.download', [$this->job, $document]);
        $generic = route('documents.download', $document);
        $this->get($dedicated)->assertOk()->assertDownload('repair.png');
        $this->get($generic)->assertOk()->assertDownload('repair.png');
        $this->actingAs($this->manager)->put(route('maintenance.status.update', $this->job), ['status' => 'open', 'assigned_to' => $this->other->id])->assertRedirect();
        $this->actingAs($this->technician)->get($dedicated)->assertNotFound();
        $this->get($generic)->assertNotFound();
        $this->post(route('maintenance.job-card.notes', $this->job), ['note' => 'Stale write'])->assertNotFound();
        $this->actingAs($this->other)->get($dedicated)->assertOk();
        $this->organization->users()->detach($this->other);
        $this->get($dedicated)->assertNotFound();
        $this->get($generic)->assertNotFound();
    }

    public function test_manager_can_void_cost_with_history_but_technician_cannot_and_actual_cost_stays_unchanged(): void
    {
        $this->actingAs($this->technician)->post(route('maintenance.job-card.costs', $this->job), ['category' => 'labor', 'description' => 'Wrong entry', 'quantity' => '2', 'unit_rate' => '10'])->assertRedirect();
        $line = JobCostLine::sole();
        $this->post(route('maintenance.job-card.costs.void', [$this->job, $line]), ['reason' => 'Duplicate'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('maintenance.job-card.costs.void', [$this->job, $line]), ['reason' => 'Duplicate'])->assertRedirect();
        $this->get(route('maintenance.job-card', $this->job))->assertInertia(fn (Assert $page) => $page->where('totals.total', '0.00')->where('job.actual_cost', '200.00')->where('job.job_cost_lines.0.void_reason', 'Duplicate'));
        $this->assertDatabaseCount('maintenance_job_cost_lines', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.job.cost_voided', 'subject_id' => $this->job->id]);
    }

    public function test_closed_job_rejects_every_record_mutation(): void
    {
        $this->actingAs($this->manager)->post(route('maintenance.job-card.tasks', $this->job), ['label' => 'Task', 'is_required' => false])->assertRedirect();
        $task = JobTask::sole();
        $this->job->update(['status' => 'completed']);
        $this->actingAs($this->technician)->post(route('maintenance.job-card.notes', $this->job), ['note' => 'Closed write'])->assertStatus(422);
        $this->put(route('maintenance.job-card.tasks.check', [$this->job, $task]), ['complete' => true])->assertStatus(422);
        $this->post(route('maintenance.job-card.costs', $this->job), ['category' => 'labor', 'description' => 'Closed', 'quantity' => '1', 'unit_rate' => '1'])->assertStatus(422);
        $this->post(route('maintenance.job-card.evidence', $this->job), ['file' => $this->image('closed.png')])->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get(route('maintenance.job-card', $this->job))->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    }

    public function test_foreign_jobs_and_child_ids_are_denied_without_writes(): void
    {
        $foreign = Organization::factory()->create();
        $property = Property::create(['organization_id' => $foreign->id, 'name' => 'Foreign', 'type' => 'residential']);
        $otherJob = MaintenanceRequest::create(['organization_id' => $foreign->id, 'property_id' => $property->id, 'reference' => 'MNT-FOREIGN', 'title' => 'Foreign', 'assigned_to' => $this->technician->id]);
        $task = JobTask::create(['organization_id' => $foreign->id, 'maintenance_request_id' => $otherJob->id, 'label' => 'Foreign task']);
        $this->actingAs($this->technician)->get(route('maintenance.job-card', $otherJob))->assertNotFound();
        $this->post(route('maintenance.job-card.notes', $otherJob), ['note' => 'Foreign write'])->assertNotFound();
        $this->put(route('maintenance.job-card.tasks.check', [$this->job, $task]), ['complete' => true])->assertNotFound();
        $this->assertNull($task->fresh()->completed_at);
        $this->assertDatabaseCount('maintenance_job_notes', 0);
    }

    public function test_failed_evidence_audit_cleans_file_and_record(): void
    {
        $audit = Mockery::mock(RecordOrganizationAuditLog::class);
        $audit->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(RecordOrganizationAuditLog::class, $audit);
        try {
            app(ManageJobCard::class)->evidence($this->organization, $this->technician, $this->job, $this->image('failed.png'));
            $this->fail('Expected auditing to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_cost_rounding_and_invalid_quantities_are_enforced_without_float_drift(): void
    {
        $amounts = app(JobCostAmount::class);
        $this->assertSame('0.01', $amounts->calculate('0.50', '0.01'));
        $this->assertSame('0.00', $amounts->calculate('0.49', '0.01'));
        $this->assertSame('999999980000.00', $amounts->calculate('999999.99', '999999.99'));
        foreach (['0', '-1', '1.001', '1000000', '1e2'] as $quantity) {
            try {
                $amounts->calculate($quantity, '1');
                $this->fail('Invalid quantity accepted: '.$quantity);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('quantity', $exception->errors());
            }
        }
    }

    public function test_daily_maintenance_alerts_count_only_the_recipients_assigned_jobs(): void
    {
        MaintenanceRequest::create(['organization_id' => $this->organization->id, 'property_id' => $this->job->property_id, 'reference' => 'MNT-OTHER-ALERT', 'title' => 'Other overdue work', 'assigned_to' => $this->other->id, 'due_at' => now()->subDay()]);
        $this->assertSame(3, app(GenerateDailyNotifications::class)->handle());
        $this->assertDatabaseHas('organization_notifications', ['user_id' => $this->manager->id, 'category' => 'overdue_maintenance', 'count' => 2]);
        $this->assertDatabaseHas('organization_notifications', ['user_id' => $this->technician->id, 'category' => 'overdue_maintenance', 'count' => 1]);
        $this->assertDatabaseHas('organization_notifications', ['user_id' => $this->other->id, 'category' => 'overdue_maintenance', 'count' => 1]);
        $this->assertSame(0, app(GenerateDailyNotifications::class)->handle());
    }

    private function image(string $name): UploadedFile
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertNotFalse($contents);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
