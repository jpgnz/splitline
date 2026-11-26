<?php

use App\Support\DateWindowResolver;
use Carbon\CarbonImmutable;

it('steps bounded day windows backward and forward and clamps future end dates to today', function () {
    $resolver = new DateWindowResolver;
    $today = CarbonImmutable::parse('2026-11-26', 'Pacific/Auckland')->startOfDay();
    $allowed = ['7d', '28d', '30d', '90d', '12w', '6m', '1y', 'all', 'custom'];

    $latest = $resolver->resolveDayWindow('30d', null, null, null, $today, $allowed, '30d');
    expect($latest['range'])->toBe('30d')
        ->and($latest['start'])->toBe('2026-10-28')
        ->and($latest['end'])->toBe('2026-11-26')
        ->and($latest['prevEnd'])->toBe('2026-10-27')
        ->and($latest['nextEnd'])->toBeNull()
        ->and($latest['isLatest'])->toBeTrue()
        ->and($latest['label'])->toBe('Oct 28 – Nov 26, 2026');

    $previous = $resolver->resolveDayWindow('30d', $latest['prevEnd'], null, null, $today, $allowed, '30d');
    expect($previous['start'])->toBe('2026-09-28')
        ->and($previous['end'])->toBe('2026-10-27')
        ->and($previous['prevEnd'])->toBe('2026-09-27')
        ->and($previous['nextEnd'])->toBe('2026-11-26')
        ->and($previous['isLatest'])->toBeFalse();

    $clampedFuture = $resolver->resolveDayWindow('30d', '2027-05-01', null, null, $today, $allowed, '30d');
    expect($clampedFuture['end'])->toBe('2026-11-26')
        ->and($clampedFuture['isLatest'])->toBeTrue()
        ->and($clampedFuture['nextEnd'])->toBeNull();
});

it('normalizes custom day windows including swapped bounds and single-sided ranges', function () {
    $resolver = new DateWindowResolver;
    $today = CarbonImmutable::parse('2026-11-26', 'Pacific/Auckland')->startOfDay();
    $allowed = ['30d', 'all', 'custom'];

    $swapped = $resolver->resolveDayWindow('custom', null, '2026-09-15', '2026-08-01', $today, $allowed, 'all');
    expect($swapped['range'])->toBe('custom')
        ->and($swapped['start'])->toBe('2026-08-01')
        ->and($swapped['end'])->toBe('2026-09-15')
        ->and($swapped['from'])->toBe('2026-08-01')
        ->and($swapped['to'])->toBe('2026-09-15')
        ->and($swapped['prevEnd'])->toBeNull()
        ->and($swapped['nextEnd'])->toBeNull()
        ->and($swapped['label'])->toBe('Aug 1 – Sep 15, 2026');

    $fromOnly = $resolver->resolveDayWindow('custom', null, '2026-10-01', null, $today, $allowed, 'all');
    expect($fromOnly['from'])->toBe('2026-10-01')
        ->and($fromOnly['to'])->toBeNull()
        ->and($fromOnly['end'])->toBe('2026-11-26')
        ->and($fromOnly['label'])->toBe('From Oct 1, 2026');
});

it('resolves stepped and custom Monday-Sunday week windows with max-week capping', function () {
    $resolver = new DateWindowResolver;
    $today = CarbonImmutable::parse('2026-11-26', 'Pacific/Auckland')->startOfDay();

    $latest = $resolver->resolveWeekWindow('12w', null, null, null, $today);
    expect($latest['range'])->toBe('12w')
        ->and($latest['start'])->toBe('2026-09-07')
        ->and($latest['end'])->toBe('2026-11-29')
        ->and($latest['prevEnd'])->toBe('2026-09-06')
        ->and($latest['nextEnd'])->toBeNull()
        ->and($latest['isLatest'])->toBeTrue();

    $previous = $resolver->resolveWeekWindow('12w', $latest['prevEnd'], null, null, $today);
    expect($previous['start'])->toBe('2026-06-15')
        ->and($previous['end'])->toBe('2026-09-06')
        ->and($previous['nextEnd'])->toBe('2026-11-29')
        ->and($previous['isLatest'])->toBeFalse();

    $custom = $resolver->resolveWeekWindow('custom', null, '2026-04-01', '2026-05-15', $today);
    expect($custom['range'])->toBe('custom')
        ->and($custom['from'])->toBe('2026-04-01')
        ->and($custom['to'])->toBe('2026-05-15')
        ->and($custom['startWeek']?->format('Y-m-d'))->toBe('2026-03-30')
        ->and($custom['endWeek']->format('Y-m-d'))->toBe('2026-05-11')
        ->and($custom['queryStart'])->toBe('2026-04-01 00:00:00')
        ->and($custom['queryEnd'])->toBe('2026-05-15 23:59:59');

    $capped = $resolver->resolveWeekWindow('custom', null, '2015-01-01', '2026-11-26', $today, maxWeeks: 52);
    expect((int) $capped['startWeek']?->diffInWeeks($capped['endWeek']))->toBe(51);
});
