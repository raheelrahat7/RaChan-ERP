<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Services\BusinessHoursCalendar;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageWorkingCalendar
{
    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, array $input): int
    {
        abort_unless(app(DealAccess::class)->administrator($org, $actor), 403);
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:0'], 'working_days' => ['required', 'array', 'min:1', 'max:7'], 'working_days.*' => ['array:start,end'], 'working_days.*.start' => ['required', 'date_format:H:i'], 'working_days.*.end' => ['required', 'date_format:H:i'], 'holidays' => ['present', 'array', 'max:366'], 'holidays.*' => ['date_format:Y-m-d', 'distinct']])->validate();
        try {
            new BusinessHoursCalendar($org->timezone, $data['working_days'], $data['holidays']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['working_days' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($org, $actor, $data): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('crm_working_calendars')->where('organization_id', $org->id)->first();
            if (($existing->version ?? 0) !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'This calendar changed. Reload it and try again.']);
            }
            $version = $existing ? $existing->version + 1 : 1;
            $values = ['working_days' => json_encode($data['working_days']), 'holidays' => json_encode($data['holidays']), 'version' => $version, 'updated_at' => now()];
            if ($existing) {
                DB::table('crm_working_calendars')->where('id', $existing->id)->update($values);
            } else {
                DB::table('crm_working_calendars')->insert(['organization_id' => $org->id, ...$values, 'created_at' => now()]);
            }
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.working_calendar.saved', null, $data);

            return $version;
        });
    }

    public function deadline(Organization $org, int $minutes): CarbonImmutable
    {
        $row = DB::table('crm_working_calendars')->where('organization_id', $org->id)->first();
        if (! $row) {
            throw ValidationException::withMessages(['working_hours_only' => 'Configure the organization working calendar first.']);
        }
        $calendar = new BusinessHoursCalendar($org->timezone, json_decode($row->working_days, true), json_decode($row->holidays, true));

        try {
            $deadline = CarbonImmutable::instance($calendar->deadline(CarbonImmutable::now()->toDateTimeImmutable(), max(1, $minutes * 60)));

            return $minutes === 0 ? $deadline->subSecond() : $deadline;
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['working_hours_only' => $exception->getMessage()]);
        }
    }
}
