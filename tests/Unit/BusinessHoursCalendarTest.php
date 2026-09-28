<?php

namespace Tests\Unit;

use App\Domain\Operations\Services\BusinessHoursCalendar;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BusinessHoursCalendarTest extends TestCase
{
    private function weekdayCalendar(): BusinessHoursCalendar
    {
        return new BusinessHoursCalendar('Asia/Karachi', array_fill_keys(range(1, 5), ['start' => '09:00', 'end' => '17:00']), ['2026-09-28']);
    }

    public function test_weekends_holidays_and_timezone_are_respected(): void
    {
        $calendar = $this->weekdayCalendar();
        $start = new DateTimeImmutable('2026-09-25T11:00:00Z'); // Friday 16:00 local.
        $deadline = $calendar->deadline($start, 7200);
        $this->assertSame('2026-09-29T05:00:00+00:00', $deadline->format('c'));
        $this->assertSame(7200, $calendar->elapsedSeconds($start, $deadline));
        $this->assertSame(0, $calendar->elapsedSeconds(new DateTimeImmutable('2026-09-26T00:00:00Z'), new DateTimeImmutable('2026-09-28T23:00:00Z')));
    }

    public function test_exact_boundaries_and_seconds_are_preserved(): void
    {
        $calendar = $this->weekdayCalendar();
        $start = new DateTimeImmutable('2026-09-29T11:59:30Z');
        $this->assertSame('2026-09-29T12:00:00+00:00', $calendar->deadline($start, 30)->format('c'));
        $this->assertSame('2026-09-30T04:00:01+00:00', $calendar->deadline($start, 31)->format('c'));
        $this->assertSame(31, $calendar->elapsedSeconds($start, $calendar->deadline($start, 31)));
        $this->assertSame($start->getTimestamp(), $calendar->deadline($start, 0)->getTimestamp());
    }

    public function test_daylight_saving_windows_count_actual_elapsed_time(): void
    {
        $calendar = new BusinessHoursCalendar('America/New_York', [7 => ['start' => '00:00', 'end' => '04:00']]);
        $spring = new DateTimeImmutable('2026-03-08T00:00:00-05:00');
        $fall = new DateTimeImmutable('2026-11-01T00:00:00-04:00');
        $this->assertSame(10800, $calendar->elapsedSeconds($spring, new DateTimeImmutable('2026-03-08T04:00:00-04:00')));
        $this->assertSame(18000, $calendar->elapsedSeconds($fall, new DateTimeImmutable('2026-11-01T04:00:00-05:00')));
        $this->assertSame('2026-03-08T08:00:00+00:00', $calendar->deadline($spring, 10800)->format('c'));
    }

    public function test_nonexistent_local_working_boundary_is_rejected(): void
    {
        $calendar = new BusinessHoursCalendar('America/New_York', [7 => ['start' => '02:30', 'end' => '04:00']]);
        $this->expectException(InvalidArgumentException::class);
        $calendar->deadline(new DateTimeImmutable('2026-03-08T00:00:00-05:00'), 60);
    }

    public function test_invalid_calendars_are_rejected(): void
    {
        foreach ([[], [0 => ['start' => '09:00', 'end' => '17:00']], [1 => ['start' => '9:00', 'end' => '17:00']], [1 => ['start' => '17:00', 'end' => '09:00']]] as $days) {
            try {
                new BusinessHoursCalendar('UTC', $days);
                $this->fail('Invalid calendar accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new BusinessHoursCalendar('UTC', [1 => ['start' => '09:00', 'end' => '17:00']], ['2026-02-30']);
    }

    public function test_reversed_intervals_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->weekdayCalendar()->elapsedSeconds(new DateTimeImmutable('2026-09-30'), new DateTimeImmutable('2026-09-29'));
    }

    public function test_negative_targets_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->weekdayCalendar()->deadline(new DateTimeImmutable('2026-09-29'), -1);
    }
}
