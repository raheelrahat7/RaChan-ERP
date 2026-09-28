<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Notifications\Models\OrganizationNotification;
use App\Domain\Operations\Models\PrivateReportDelivery;
use App\Domain\Operations\Models\PrivateReportSchedule;
use App\Domain\Operations\Queries\OperationsReport;
use App\Domain\Operations\Queries\OperationsReportExport;
use App\Domain\Operations\Services\ReportFiles;
use App\Domain\Operations\Services\ReportFilterRules;
use App\Domain\Operations\Services\ReportScheduleClock;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GeneratePrivateReports
{
    public function __construct(private OperationsReport $report, private OperationsReportExport $export, private ReportFiles $files, private ReportScheduleClock $clock, private ReportFilterRules $rules) {}

    /** @return array{generated:int,failed:int} */
    public function handle(): array
    {
        $result = ['generated' => 0, 'failed' => 0];
        $at = CarbonImmutable::now('UTC');
        PrivateReportSchedule::where('enabled', true)->where('next_run_at', '<=', $at)->orderBy('id')->chunkById(25, function ($schedules) use (&$result, $at): void {
            foreach ($schedules as $candidate) {
                $path = null;
                try {
                    $status = DB::transaction(function () use ($candidate, $at, &$path): ?string {
                        $org = Organization::whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
                        $schedule = PrivateReportSchedule::lockForUpdate()->findOrFail($candidate->id);
                        if (! $schedule->enabled || $schedule->next_run_at->greaterThan($at)) {
                            return null;
                        }
                        $delivery = PrivateReportDelivery::firstOrCreate(['private_report_schedule_id' => $schedule->id, 'scheduled_for' => $schedule->next_run_at], ['organization_id' => $org->id, 'user_id' => $schedule->user_id, 'format' => $schedule->format, 'status' => 'generating']);
                        $schedule->update(['next_run_at' => $this->clock->next($org, $schedule->frequency, $schedule->local_time, $schedule->weekday, $at)]);
                        if ($delivery->status !== 'generating') {
                            return null;
                        }
                        $actor = User::find($schedule->user_id);
                        if ($actor === null || ! $actor->hasVerifiedEmail() || ! $actor->belongsToOrganization($org) || ! $actor->can('viewOperations', $org)) {
                            $delivery->update(['status' => 'blocked', 'failure_reason' => 'Creator no longer has verified organization report access.']);

                            return 'failed';
                        }
                        if (Validator::make($schedule->filters, $this->rules->for($org->id, ! empty($schedule->filters['created_from'])))->fails()) {
                            $delivery->update(['status' => 'blocked', 'failure_reason' => 'A saved filter is no longer valid.']);

                            return 'failed';
                        }
                        $ids = [];
                        foreach ($this->report->rows($org, $actor, $schedule->filters, $at) as $row) {
                            $ids[] = (int) $row['id'];
                            if (count($ids) > 1000) {
                                $delivery->update(['status' => 'failed', 'failure_reason' => 'Report exceeds 1000 jobs. Narrow the saved filters.']);

                                return 'failed';
                            }
                        }
                        $stream = fopen('php://temp', 'w+');
                        if ($stream === false) {
                            throw new RuntimeException('Cannot create report buffer.');
                        }
                        try {
                            $this->export->write($stream, $org, $actor, $schedule->filters, $at);
                            rewind($stream);
                            $rows = [];
                            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                                $rows[] = array_map(fn ($value): string => (string) ($value ?? ''), $row);
                                if (count($rows) > 10000) {
                                    throw new RuntimeException('Report exceeds the supported row limit.');
                                }
                            }
                        } finally {
                            fclose($stream);
                        }
                        $bytes = $schedule->format === 'pdf' ? $this->files->pdf($rows) : $this->files->xlsx($rows);
                        $path = 'private-reports/'.Str::uuid().'.'.$schedule->format;
                        if (! Storage::disk('local')->put($path, $bytes)) {
                            throw new RuntimeException('Could not store report.');
                        }
                        $delivery->update(['status' => 'ready', 'path' => $path, 'job_ids' => $ids, 'generated_at' => $at]);
                        OrganizationNotification::firstOrCreate(['organization_id' => $org->id, 'user_id' => $actor->id, 'event_key' => 'private-report:'.$delivery->id], ['category' => 'scheduled_report', 'title' => $schedule->name.' is ready', 'count' => 1, 'href' => '/operations/scheduled-reports']);

                        return 'generated';
                    });
                    if ($status !== null) {
                        $result[$status]++;
                    }
                } catch (Throwable $error) {
                    if ($path !== null) {
                        Storage::disk('local')->delete($path);
                    }
                    report($error);
                    DB::transaction(function () use ($candidate, $at): void {
                        $org = Organization::whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
                        $schedule = PrivateReportSchedule::lockForUpdate()->findOrFail($candidate->id);
                        if (! $schedule->enabled || ! $schedule->next_run_at->equalTo($candidate->next_run_at)) {
                            return;
                        }
                        PrivateReportDelivery::firstOrCreate(['private_report_schedule_id' => $schedule->id, 'scheduled_for' => $schedule->next_run_at], ['organization_id' => $org->id, 'user_id' => $schedule->user_id, 'format' => $schedule->format, 'status' => 'failed', 'failure_reason' => 'Generation failed. Review the application log and create a narrower schedule if necessary.']);
                        $schedule->update(['next_run_at' => $this->clock->next($org, $schedule->frequency, $schedule->local_time, $schedule->weekday, $at)]);
                    });
                    $result['failed']++;
                }
            }
        });

        return $result;
    }
}
