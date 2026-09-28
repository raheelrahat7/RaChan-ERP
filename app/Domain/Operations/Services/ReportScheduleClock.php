<?php

namespace App\Domain\Operations\Services;

use App\Models\Organization;
use Carbon\CarbonImmutable;

class ReportScheduleClock
{
    public function next(Organization $org, string $frequency, string $time, int $weekday, CarbonImmutable $after): CarbonImmutable
    {
        $local = $after->setTimezone($org->timezone);
        [$hour,$minute] = array_map('intval', explode(':', $time));
        $candidate = $local->startOfDay()->setTime($hour, $minute);
        for ($i = 0; $i < 9; $i++) {
            if ($candidate->greaterThan($after) && ($frequency === 'daily' || $candidate->dayOfWeekIso === $weekday)) {
                return $candidate->utc();
            }$candidate = $candidate->addDay()->setTime($hour, $minute);
        }
        throw new \RuntimeException('Cannot determine next report occurrence.');
    }
}
