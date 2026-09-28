<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Models\JobSlaCycle;
use DateTimeImmutable;

class JobSlaClock
{
    /** @return array<string, mixed> */
    public function summary(JobSlaCycle $cycle, ?DateTimeImmutable $at = null): array
    {
        $end = $cycle->closed_at ?? $at ?? now()->toImmutable();
        $response = $this->elapsed($cycle, $cycle->acknowledged_at ?? $end);
        $resolution = $this->elapsed($cycle, $end);

        return [...$cycle->toArray(), 'response_elapsed_seconds' => $response, 'resolution_elapsed_seconds' => $resolution,
            'response_breached' => $cycle->outcome !== 'cancelled' && $response > $cycle->response_seconds,
            'resolution_breached' => $cycle->outcome !== 'cancelled' && $resolution > $cycle->resolution_seconds];
    }

    public function elapsed(JobSlaCycle $cycle, DateTimeImmutable $end): int
    {
        $calendar = new BusinessHoursCalendar($cycle->timezone, $cycle->working_days, array_values($cycle->holidays));
        $start = $cycle->started_at;
        $end = max($start, $end);
        $seconds = $calendar->elapsedSeconds($start, $end);
        foreach ($cycle->holds as $hold) {
            $from = max($start, new DateTimeImmutable($hold['start']));
            $until = min($end, new DateTimeImmutable($hold['end']));
            if ($until > $from) {
                $seconds -= $calendar->elapsedSeconds($from, $until);
            }
        }
        if ($cycle->held_at !== null && $end > $cycle->held_at) {
            $seconds -= $calendar->elapsedSeconds(max($start, $cycle->held_at), $end);
        }

        return max(0, $seconds);
    }
}
