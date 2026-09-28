<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Models\PrivateReportSchedule;
use App\Domain\Operations\Services\ReportFilterRules;
use App\Domain\Operations\Services\ReportScheduleClock;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManagePrivateReportSchedules
{
    public function __construct(private ReportFilterRules $rules, private ReportScheduleClock $clock, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function create(Organization $org, User $actor, array $input): PrivateReportSchedule
    {
        Gate::forUser($actor)->authorize('viewOperations', $org);
        abort_unless($actor->belongsToOrganization($org) && $actor->hasVerifiedEmail(), 403);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'format' => ['required', 'in:pdf,xlsx'], 'frequency' => ['required', 'in:daily,weekly'], 'local_time' => ['required', 'date_format:H:i'], 'weekday' => ['required', 'integer', 'between:1,7'], 'filters' => ['present', 'array']])->validate();
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Enter a report name.']);
        }
        $filters = Validator::make($data['filters'], $this->rules->for($org->id, ! empty($data['filters']['created_from'])))->validate();
        $data['filters'] = array_filter($filters, fn ($v): bool => $v !== null && $v !== '');

        return DB::transaction(function () use ($org, $actor, $data): PrivateReportSchedule {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (PrivateReportSchedule::where('organization_id', $org->id)->where('user_id', $actor->id)->count() >= 20) {
                throw ValidationException::withMessages(['name' => 'The limit is 20 schedules per organization and creator.']);
            }
            $schedule = PrivateReportSchedule::create([...$data, 'organization_id' => $org->id, 'user_id' => $actor->id, 'next_run_at' => $this->clock->next($org, $data['frequency'], $data['local_time'], (int) $data['weekday'], CarbonImmutable::now('UTC'))]);
            $this->audit->handle($org, $actor, 'operations.report.schedule_created', $schedule, ['format' => $schedule->format, 'frequency' => $schedule->frequency]);

            return $schedule;
        });
    }

    public function enabled(Organization $org, User $actor, int $id, bool $enabled): void
    {
        Gate::forUser($actor)->authorize('viewOperations', $org);
        DB::transaction(function () use ($org, $actor, $id, $enabled): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $schedule = PrivateReportSchedule::where('organization_id', $org->id)->where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            if ($schedule->enabled === $enabled) {
                return;
            }
            $schedule->update(['enabled' => $enabled, 'next_run_at' => $this->clock->next($org, $schedule->frequency, $schedule->local_time, $schedule->weekday, CarbonImmutable::now('UTC'))]);
            $this->audit->handle($org, $actor, 'operations.report.schedule_toggled', $schedule, ['enabled' => $enabled]);
        });
    }
}
