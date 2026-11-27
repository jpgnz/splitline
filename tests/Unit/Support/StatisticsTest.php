<?php

use App\Support\Statistics;

it('computes Pearson r and returns null for short, mismatched, or zero-variance series', function () {
    $statistics = new Statistics;

    expect($statistics->pearson([1.0, 2.0, 3.0, 4.0, 5.0], [2.0, 4.0, 6.0, 8.0, 10.0]))->toEqualWithDelta(1.0, 1e-12)
        ->and($statistics->pearson([1.0, 2.0, 3.0, 4.0, 5.0], [10.0, 8.0, 6.0, 4.0, 2.0]))->toEqualWithDelta(-1.0, 1e-12)
        ->and($statistics->pearson([1.0, 2.0, 3.0, 4.0, 5.0], [2.0, 3.0, 5.0, 4.0, 6.0]))->toEqualWithDelta(0.9, 1e-12)
        ->and($statistics->pearson([1.0, 2.0], [2.0, 4.0]))->toBeNull()
        ->and($statistics->pearson([1.0, 2.0, 3.0], [2.0, 4.0]))->toBeNull()
        ->and($statistics->pearson([5.0, 5.0, 5.0, 5.0, 5.0], [1.0, 2.0, 3.0, 4.0, 5.0]))->toBeNull();
});

it('computes two-sided Student-t tail probabilities that match reference tables', function () {
    $statistics = new Statistics;

    expect($statistics->studentTTwoTailedPValue(2.228139, 10.0))->toEqualWithDelta(0.05, 1e-5)
        ->and($statistics->studentTTwoTailedPValue(2.0, 10.0))->toEqualWithDelta(0.07339, 1e-5)
        ->and($statistics->studentTTwoTailedPValue(-2.0, 10.0))->toEqualWithDelta(0.07339, 1e-5)
        ->and($statistics->studentTTwoTailedPValue(1.0, 1.0))->toEqualWithDelta(0.5, 1e-9)
        ->and($statistics->studentTTwoTailedPValue(0.0, 5.0))->toEqualWithDelta(1.0, 1e-12)
        ->and($statistics->studentTTwoTailedPValue(12.706205, 1.0))->toEqualWithDelta(0.05, 1e-5);
});

it('derives correlation p-values from r and n with n − 2 degrees of freedom', function () {
    $statistics = new Statistics;

    // Critical |r| for p = 0.05 with n = 10 is 0.632; with n = 5 it is 0.878.
    expect($statistics->correlationPValue(0.632, 10))->toEqualWithDelta(0.05, 5e-4)
        ->and($statistics->correlationPValue(-0.632, 10))->toEqualWithDelta(0.05, 5e-4)
        ->and($statistics->correlationPValue(0.878, 5))->toEqualWithDelta(0.05, 5e-4)
        ->and($statistics->correlationPValue(0.5, 5))->toBeGreaterThan(0.3)
        ->and($statistics->correlationPValue(1.0, 6))->toBe(0.0)
        ->and($statistics->correlationPValue(0.9, 2))->toBeNull();
});

it('runs a Welch unequal-variance t-test and returns null when it cannot be evaluated', function () {
    $statistics = new Statistics;

    // Means 3 vs 5, both variances 2.5, n = 5 → t = −2, Welch df = 8.
    expect($statistics->welchTTestPValue([1.0, 2.0, 3.0, 4.0, 5.0], [3.0, 4.0, 5.0, 6.0, 7.0]))->toEqualWithDelta(0.08052, 1e-5)
        ->and($statistics->welchTTestPValue([1.0, 1.1, 0.9], [2.0, 2.1, 1.9]))->toBeLessThan(0.001)
        ->and($statistics->welchTTestPValue([1.0], [2.0, 3.0]))->toBeNull()
        ->and($statistics->welchTTestPValue([2.0, 2.0, 2.0], [3.0, 3.0, 3.0]))->toBeNull();
});
