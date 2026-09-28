<?php

namespace App\Domain\Operations\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A configured local calendar; durations count actual elapsed seconds inside working windows. */
final class BusinessHoursCalendar
{
    private DateTimeZone $zone;

    /**
     * @param  array<int, array{start: string, end: string}>  $workingDays  ISO weekdays: Monday=1, Sunday=7.
     * @param  list<string>  $holidays  Local dates in YYYY-MM-DD format.
     */
    public function __construct(string $timezone, private array $workingDays, private array $holidays = [])
    {
        $this->zone = new DateTimeZone($timezone);
        if ($workingDays === []) {
            throw new InvalidArgumentException('At least one working day is required.');
        }
        foreach ($workingDays as $day => $window) {
            if ($day < 1 || $day > 7 || ! $this->validTime($window['start']) || ! $this->validTime($window['end']) || $window['start'] >= $window['end']) {
                throw new InvalidArgumentException('Working windows must start and end on the same day, with the end after the start.');
            }
        }
        foreach ($holidays as $holiday) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday, $this->zone);
            if ($date === false || $date->format('Y-m-d') !== $holiday) {
                throw new InvalidArgumentException('Holidays must be valid local dates.');
            }
        }
    }

    public function elapsedSeconds(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end < $start) {
            throw new InvalidArgumentException('The end must not precede the start.');
        }
        $total = 0;
        $day = $start->setTimezone($this->zone)->setTime(0, 0);
        $last = $end->setTimezone($this->zone)->setTime(0, 0);
        $this->requireRange($day, $last);
        while ($day <= $last) {
            $window = $this->window($day);
            if ($window !== null) {
                $total += max(0, min($end->getTimestamp(), $window[1]->getTimestamp()) - max($start->getTimestamp(), $window[0]->getTimestamp()));
            }
            $day = $day->modify('+1 day');
        }

        return $total;
    }

    public function deadline(DateTimeImmutable $start, int $workingSeconds): DateTimeImmutable
    {
        if ($workingSeconds < 0) {
            throw new InvalidArgumentException('Working seconds must not be negative.');
        }
        if ($workingSeconds === 0) {
            return $start->setTimezone(new DateTimeZone('UTC'));
        }
        $day = $start->setTimezone($this->zone)->setTime(0, 0);
        $limit = $day->modify('+10 years');
        while ($day <= $limit) {
            $window = $this->window($day);
            if ($window !== null) {
                $from = max($start->getTimestamp(), $window[0]->getTimestamp());
                $available = max(0, $window[1]->getTimestamp() - $from);
                if ($available >= $workingSeconds) {
                    return (new DateTimeImmutable('@'.($from + $workingSeconds)))->setTimezone(new DateTimeZone('UTC'));
                }
                $workingSeconds -= $available;
            }
            $day = $day->modify('+1 day');
        }

        throw new InvalidArgumentException('The SLA deadline exceeds the ten-year calculation limit.');
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable}|null */
    private function window(DateTimeImmutable $day): ?array
    {
        $hours = $this->workingDays[(int) $day->format('N')] ?? null;
        if ($hours === null || in_array($day->format('Y-m-d'), $this->holidays, true)) {
            return null;
        }
        $date = $day->format('Y-m-d');
        $start = new DateTimeImmutable($date.' '.$hours['start'], $this->zone);
        $end = new DateTimeImmutable($date.' '.$hours['end'], $this->zone);

        // Reject windows silently normalized across a daylight-saving gap.
        if ($start->format('H:i') !== $hours['start'] || $end->format('H:i') !== $hours['end'] || $end <= $start) {
            throw new InvalidArgumentException('A working window falls on an invalid local time.');
        }

        return [$start, $end];
    }

    private function validTime(string $time): bool
    {
        return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) === 1;
    }

    private function requireRange(DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        if ($end > $start->modify('+10 years')) {
            throw new InvalidArgumentException('The SLA interval exceeds the ten-year calculation limit.');
        }
    }
}
