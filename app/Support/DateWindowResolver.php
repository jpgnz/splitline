<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Resolves bounded, stepped, and custom date windows consistently across hubs,
 * health drilldowns, activities, and the route heatmap.
 *
 * @phpstan-type DateWindow array{
 *     range: string,
 *     start: ?string,
 *     end: string,
 *     from: ?string,
 *     to: ?string,
 *     prevEnd: ?string,
 *     nextEnd: ?string,
 *     isLatest: bool,
 *     label: string,
 * }
 * @phpstan-type WeekWindow array{
 *     range: string,
 *     start: ?string,
 *     end: string,
 *     from: ?string,
 *     to: ?string,
 *     prevEnd: ?string,
 *     nextEnd: ?string,
 *     isLatest: bool,
 *     label: string,
 *     startWeek: ?CarbonImmutable,
 *     endWeek: CarbonImmutable,
 *     queryStart: ?string,
 *     queryEnd: string,
 * }
 */
class DateWindowResolver
{
    /** @var array<string, int> */
    private const array DAY_PRESET_LENGTHS = [
        '7d' => 7,
        '28d' => 28,
        '30d' => 30,
        '90d' => 90,
        '12w' => 84,
        '6m' => 182,
        '1y' => 365,
    ];

    /** @var array<string, int> */
    private const array WEEK_PRESET_LENGTHS = [
        '12w' => 12,
        '6m' => 26,
        '1y' => 52,
    ];

    /**
     * Resolve a day-based date window with backward/forward stepping and custom date range support.
     *
     * @param  list<string>  $allowedRanges
     * @return DateWindow
     */
    public function resolveDayWindow(
        ?string $range,
        ?string $end,
        ?string $from,
        ?string $to,
        CarbonImmutable $today,
        array $allowedRanges,
        string $defaultRange,
    ): array {
        $todayDay = $today->startOfDay();
        $todayStr = $todayDay->format('Y-m-d');
        $timezone = $todayDay->getTimezone();
        $parsedFrom = $this->parseDate($from, $timezone);
        $parsedTo = $this->parseDate($to, $timezone);

        $normalizedRange = $range !== null && in_array($range, $allowedRanges, true)
            ? $range
            : (in_array('custom', $allowedRanges, true) && ($parsedFrom !== null || $parsedTo !== null)
                ? 'custom'
                : $defaultRange);

        if ($normalizedRange === 'all') {
            return [
                'range' => 'all',
                'start' => null,
                'end' => $todayStr,
                'from' => null,
                'to' => null,
                'prevEnd' => null,
                'nextEnd' => null,
                'isLatest' => true,
                'label' => 'All time',
            ];
        }

        if ($normalizedRange === 'custom') {
            [$resolvedFrom, $resolvedTo] = $this->normalizeBounds($parsedFrom, $parsedTo);
            $startStr = $resolvedFrom?->format('Y-m-d');
            $toStr = $resolvedTo?->format('Y-m-d');
            $endStr = $toStr ?? $todayStr;

            return [
                'range' => 'custom',
                'start' => $startStr,
                'end' => $endStr,
                'from' => $startStr,
                'to' => $toStr,
                'prevEnd' => null,
                'nextEnd' => null,
                'isLatest' => true,
                'label' => $this->formatRangeLabel($resolvedFrom, $resolvedTo, 'Custom range'),
            ];
        }

        if ($normalizedRange === 'this_year' || $normalizedRange === 'last_year') {
            $parsedEnd = $this->parseDate($end, $timezone);
            $defaultYear = $normalizedRange === 'last_year' ? $todayDay->year - 1 : $todayDay->year;
            $targetYear = $parsedEnd !== null ? min($todayDay->year, $parsedEnd->year) : $defaultYear;

            $yearStart = CarbonImmutable::create($targetYear, 1, 1, 0, 0, 0, $todayDay->timezone)->startOfDay();
            $yearEnd = CarbonImmutable::create($targetYear, 12, 31, 0, 0, 0, $todayDay->timezone)->startOfDay();
            $isLatest = $targetYear >= $todayDay->year;

            return [
                'range' => $normalizedRange,
                'start' => $yearStart->format('Y-m-d'),
                'end' => $yearEnd->format('Y-m-d'),
                'from' => null,
                'to' => null,
                'prevEnd' => ($targetYear - 1).'-12-31',
                'nextEnd' => $isLatest ? null : ($targetYear + 1).'-12-31',
                'isLatest' => $isLatest,
                'label' => (string) $targetYear,
            ];
        }

        $days = self::DAY_PRESET_LENGTHS[$normalizedRange] ?? 30;
        $parsedEnd = $this->parseDate($end, $timezone);
        $anchorEnd = $parsedEnd !== null && $parsedEnd->lessThan($todayDay) ? $parsedEnd : $todayDay;
        $windowStart = $anchorEnd->subDays($days - 1);
        $isLatest = $anchorEnd->greaterThanOrEqualTo($todayDay);

        $prevEnd = $windowStart->subDay()->format('Y-m-d');
        $nextCandidate = $anchorEnd->addDays($days);
        $nextEnd = $isLatest
            ? null
            : ($nextCandidate->greaterThan($todayDay) ? $todayDay : $nextCandidate)->format('Y-m-d');

        return [
            'range' => $normalizedRange,
            'start' => $windowStart->format('Y-m-d'),
            'end' => $anchorEnd->format('Y-m-d'),
            'from' => null,
            'to' => null,
            'prevEnd' => $prevEnd,
            'nextEnd' => $nextEnd,
            'isLatest' => $isLatest,
            'label' => $this->formatRangeLabel($windowStart, $anchorEnd),
        ];
    }

    /**
     * Resolve a Monday–Sunday week-aligned window for weekly volume charts.
     *
     * @return WeekWindow
     */
    public function resolveWeekWindow(
        ?string $range,
        ?string $end,
        ?string $from,
        ?string $to,
        CarbonImmutable $today,
        int $maxWeeks = 260,
    ): array {
        $todayDay = $today->startOfDay();
        $timezone = $todayDay->getTimezone();
        $currentWeekStart = $todayDay->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $currentWeekEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();

        $allowed = ['12w', '6m', '1y', 'all', 'custom'];
        $parsedFrom = $this->parseDate($from, $timezone);
        $parsedTo = $this->parseDate($to, $timezone);

        $normalizedRange = $range !== null && in_array($range, $allowed, true)
            ? $range
            : (($parsedFrom !== null || $parsedTo !== null) ? 'custom' : '12w');

        if ($normalizedRange === 'all') {
            return [
                'range' => 'all',
                'start' => null,
                'end' => $currentWeekEnd->format('Y-m-d'),
                'from' => null,
                'to' => null,
                'prevEnd' => null,
                'nextEnd' => null,
                'isLatest' => true,
                'label' => 'All time',
                'startWeek' => null,
                'endWeek' => $currentWeekStart,
                'queryStart' => null,
                'queryEnd' => $currentWeekEnd->format('Y-m-d').' 23:59:59',
            ];
        }

        if ($normalizedRange === 'custom') {
            [$resolvedFrom, $resolvedTo] = $this->normalizeBounds($parsedFrom, $parsedTo);

            if ($resolvedFrom === null && $resolvedTo === null) {
                $resolvedTo = $todayDay;
                $resolvedFrom = $currentWeekStart->subWeeks(11);
            } elseif ($resolvedFrom === null) {
                $resolvedFrom = $resolvedTo->startOfWeek(CarbonInterface::MONDAY)->subWeeks(11);
            } elseif ($resolvedTo === null) {
                $resolvedTo = $todayDay->greaterThanOrEqualTo($resolvedFrom) ? $todayDay : $resolvedFrom;
            }

            $endWeek = $resolvedTo->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
            $startWeek = $resolvedFrom->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

            $minAllowedWeek = $endWeek->subWeeks(max(1, $maxWeeks) - 1);

            if ($startWeek->lessThan($minAllowedWeek)) {
                $startWeek = $minAllowedWeek;
                $resolvedFrom = $startWeek;
            }

            return [
                'range' => 'custom',
                'start' => $resolvedFrom->format('Y-m-d'),
                'end' => $resolvedTo->format('Y-m-d'),
                'from' => $resolvedFrom->format('Y-m-d'),
                'to' => $resolvedTo->format('Y-m-d'),
                'prevEnd' => null,
                'nextEnd' => null,
                'isLatest' => true,
                'label' => $this->formatRangeLabel($resolvedFrom, $resolvedTo),
                'startWeek' => $startWeek,
                'endWeek' => $endWeek,
                'queryStart' => $resolvedFrom->format('Y-m-d').' 00:00:00',
                'queryEnd' => $resolvedTo->format('Y-m-d').' 23:59:59',
            ];
        }

        $weeks = self::WEEK_PRESET_LENGTHS[$normalizedRange];
        $parsedEnd = $this->parseDate($end, $timezone);
        $anchorWeekStart = $parsedEnd !== null
            ? $parsedEnd->startOfWeek(CarbonInterface::MONDAY)->startOfDay()
            : $currentWeekStart;

        if ($anchorWeekStart->greaterThan($currentWeekStart)) {
            $anchorWeekStart = $currentWeekStart;
        }

        $startWeek = $anchorWeekStart->subWeeks($weeks - 1);
        $windowEnd = $anchorWeekStart->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $isLatest = $anchorWeekStart->greaterThanOrEqualTo($currentWeekStart);

        $prevEnd = $startWeek->subDay()->format('Y-m-d');
        $nextWeekStart = $anchorWeekStart->addWeeks($weeks);
        $nextWeekEnd = $nextWeekStart->greaterThan($currentWeekStart)
            ? $currentWeekEnd
            : $nextWeekStart->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();

        return [
            'range' => $normalizedRange,
            'start' => $startWeek->format('Y-m-d'),
            'end' => $windowEnd->format('Y-m-d'),
            'from' => null,
            'to' => null,
            'prevEnd' => $prevEnd,
            'nextEnd' => $isLatest ? null : $nextWeekEnd->format('Y-m-d'),
            'isLatest' => $isLatest,
            'label' => $this->formatRangeLabel($startWeek, $windowEnd),
            'startWeek' => $startWeek,
            'endWeek' => $anchorWeekStart,
            'queryStart' => $startWeek->format('Y-m-d').' 00:00:00',
            'queryEnd' => $windowEnd->format('Y-m-d').' 23:59:59',
        ];
    }

    /**
     * Extract only the serializable frontend DateWindow props from a WeekWindow.
     *
     * @param  WeekWindow  $weekWindow
     * @return DateWindow
     */
    public function toPublicWindow(array $weekWindow): array
    {
        return [
            'range' => $weekWindow['range'],
            'start' => $weekWindow['start'],
            'end' => $weekWindow['end'],
            'from' => $weekWindow['from'],
            'to' => $weekWindow['to'],
            'prevEnd' => $weekWindow['prevEnd'],
            'nextEnd' => $weekWindow['nextEnd'],
            'isLatest' => $weekWindow['isLatest'],
            'label' => $weekWindow['label'],
        ];
    }

    public function parseDate(?string $value, ?DateTimeZone $timezone = null): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $trimmed, $timezone);

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $trimmed) {
            return null;
        }

        return $parsed->startOfDay();
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function normalizeBounds(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private function formatRangeLabel(
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        string $fallback = 'All time',
    ): string {
        if ($start !== null && $end !== null) {
            if ($start->isSameDay($end)) {
                return $start->format('M j, Y');
            }

            if ($start->year === $end->year) {
                return $start->format('M j').' – '.$end->format('M j, Y');
            }

            return $start->format('M j, Y').' – '.$end->format('M j, Y');
        }

        if ($start !== null) {
            return 'From '.$start->format('M j, Y');
        }

        if ($end !== null) {
            return 'Until '.$end->format('M j, Y');
        }

        return $fallback;
    }
}
