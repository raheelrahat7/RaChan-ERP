<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Domain\Operations\Actions\GeneratePrivateReports;
use App\Domain\Operations\Actions\ManagePrivateReportSchedules;
use App\Domain\Operations\Models\PrivateReportDelivery;
use App\Domain\Operations\Services\ReportFiles;
use App\Domain\Operations\Services\ReportScheduleClock;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_delivery_is_idempotent_and_rechecks_permissions_and_assignments(): void
    {
        Storage::fake('local');
        $this->withoutVite();
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $other = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach([$user->id => ['role' => OrganizationRole::Member->value], $other->id => ['role' => OrganizationRole::Owner->value]]);
        $property = Property::create(['organization_id' => $org->id, 'name' => 'Workshop', 'type' => 'commercial']);
        $job = MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $property->id, 'reference' => 'PRIVATE', 'title' => 'Assigned service', 'priority' => 'medium', 'status' => 'open', 'assigned_to' => $user->id]);
        $schedule = app(ManagePrivateReportSchedules::class)->create($org, $user, ['name' => 'My jobs', 'format' => 'xlsx', 'frequency' => 'daily', 'local_time' => '08:00', 'weekday' => 1, 'filters' => []]);
        $schedule->update(['next_run_at' => now()->subDays(4)]);
        $result = app(GeneratePrivateReports::class)->handle();
        $this->assertSame(['generated' => 1, 'failed' => 0], $result);
        $delivery = PrivateReportDelivery::sole();
        Storage::disk('local')->assertExists($delivery->path);
        $this->assertSame([$job->id], $delivery->job_ids);
        $this->assertSame($user->id, OrganizationNotification::sole()->user_id);
        $this->assertSame(['generated' => 0, 'failed' => 0], app(GeneratePrivateReports::class)->handle());
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
        $this->actingAs($user)->get(route('private-reports.download', $delivery))->assertOk()->assertDownload();
        $this->actingAs($other)->get(route('private-reports.download', $delivery))->assertNotFound();
        $job->update(['assigned_to' => $other->id]);
        $this->actingAs($user)->get(route('private-reports.download', $delivery))->assertForbidden();
        $org->users()->detach($user);
        $schedule->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(['generated' => 0, 'failed' => 1], app(GeneratePrivateReports::class)->handle());
        $this->assertSame('blocked', PrivateReportDelivery::latest('id')->first()->status);
    }

    public function test_pdf_is_generated_and_workbook_is_readable_by_an_independent_zip_reader(): void
    {
        $files = app(ReportFiles::class);
        $rows = [['Operations report', 'العربية'], ['=1+2', '<script>alert(1)</script>', 'A & B']];
        $pdf = $files->pdf($rows);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $path = tempnam(sys_get_temp_dir(), 'report-');
        unlink($path);
        $path .= '.zip';
        file_put_contents($path, $files->xlsx($rows));
        try {
            $archive = new \PharData($path);
            $this->assertCount(5, $archive);
            $xml = $archive['xl/worksheets/sheet1.xml']->getContent();
            $document = new \DOMDocument;
            $this->assertTrue($document->loadXML($xml));
            $this->assertSame('=1+2', $document->getElementsByTagName('t')->item(2)->textContent);
            $this->assertSame(0, $document->getElementsByTagName('f')->length);
            $this->assertStringContainsString('العربية', $xml);
        } finally {
            unlink($path);
        }
    }

    public function test_schedule_uses_local_time_and_coalesces_missed_occurrences(): void
    {
        $org = Organization::factory()->create(['timezone' => 'Asia/Karachi']);
        $clock = app(ReportScheduleClock::class);
        $this->assertSame('2026-09-28 03:00:00', $clock->next($org, 'daily', '08:00', 1, CarbonImmutable::parse('2026-09-27 04:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 03:00:00', $clock->next($org, 'weekly', '08:00', 1, CarbonImmutable::parse('2026-09-28 04:00:00', 'UTC'))->format('Y-m-d H:i:s'));
    }
}
