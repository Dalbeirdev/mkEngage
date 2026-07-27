<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Business-hours evaluation (Phase 23). Config lives in
 * organizations.settings['business_hours']:
 *
 *   {
 *     "enabled": true,
 *     "timezone": "America/New_York",
 *     "schedule": { "mon": [["09:00","17:00"]], ..., "sun": [] }
 *   }
 *
 * Absent or disabled config means ALWAYS OPEN (fail-open by design: a
 * misconfigured schedule must never silence the widget). Evaluation is
 * fail-open on malformed entries for the same reason.
 */
final class BusinessHours
{
    /** @var list<string> */
    public const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function isOpen(Organization $organization, ?CarbonImmutable $at = null): bool
    {
        $config = $this->config($organization);

        if ($config === null || ($config['enabled'] ?? false) !== true) {
            return true;
        }

        $timezone = $config['timezone'] ?? 'UTC';
        if (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return true;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone($timezone);
        $day = self::DAY_KEYS[$now->dayOfWeekIso - 1] ?? 'mon';

        $schedule = $config['schedule'] ?? [];
        if (! is_array($schedule)) {
            return true;
        }

        $ranges = $schedule[$day] ?? [];
        if (! is_array($ranges)) {
            return true;
        }

        $minutes = $now->hour * 60 + $now->minute;

        foreach ($ranges as $range) {
            if (! is_array($range) || count($range) !== 2) {
                continue;
            }
            $start = $this->toMinutes($range[0] ?? null);
            $end = $this->toMinutes($range[1] ?? null);
            if ($start === null || $end === null) {
                continue;
            }
            if ($minutes >= $start && $minutes < $end) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed>|null */
    public function config(Organization $organization): ?array
    {
        $settings = $organization->settings;
        $config = is_array($settings) ? ($settings['business_hours'] ?? null) : null;

        return is_array($config) ? $config : null;
    }

    private function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
