<?php

namespace App\Support;

/**
 * Small, dependency-free inferential statistics toolkit: Pearson correlation, its two-sided
 * significance via the Student-t distribution, and Welch's unequal-variance t-test.
 */
class Statistics
{
    private const int CONTINUED_FRACTION_MAX_ITERATIONS = 300;

    private const float CONTINUED_FRACTION_EPSILON = 3.0e-14;

    private const float FLOAT_MIN = 1.0e-300;

    /** @var list<float> */
    private const array LANCZOS_COEFFICIENTS = [
        0.99999999999980993,
        676.5203681218851,
        -1259.1392167224028,
        771.32342877765313,
        -176.61502916214059,
        12.507343278686905,
        -0.13857109526572012,
        9.9843695780195716e-6,
        1.5056327351493116e-7,
    ];

    /**
     * Pearson product-moment correlation coefficient (unrounded). Returns null when the series
     * differ in length, have fewer than 3 pairs, or either series has zero variance.
     *
     * @param  list<float>  $xs
     * @param  list<float>  $ys
     */
    public function pearson(array $xs, array $ys): ?float
    {
        $n = count($xs);

        if ($n < 3 || $n !== count($ys)) {
            return null;
        }

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sumXY = 0.0;
        $sumX2 = 0.0;
        $sumY2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $dy = $ys[$i] - $meanY;
            $sumXY += $dx * $dy;
            $sumX2 += $dx * $dx;
            $sumY2 += $dy * $dy;
        }

        $denominator = sqrt($sumX2 * $sumY2);

        if ($denominator <= 1e-12) {
            return null;
        }

        return max(-1.0, min(1.0, $sumXY / $denominator));
    }

    /**
     * Two-sided p-value for H0: ρ = 0, using t = r·√((n−2)/(1−r²)) with n−2 degrees of freedom.
     */
    public function correlationPValue(float $r, int $n): ?float
    {
        if ($n < 3) {
            return null;
        }

        $rSquared = $r * $r;

        if ($rSquared >= 1.0) {
            return 0.0;
        }

        $t = $r * sqrt(($n - 2) / (1.0 - $rSquared));

        return $this->studentTTwoTailedPValue($t, (float) ($n - 2));
    }

    /**
     * Two-sided p-value of Welch's unequal-variance t-test comparing the means of two samples.
     * Returns null when either sample has fewer than 2 values or both samples have zero variance.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function welchTTestPValue(array $a, array $b): ?float
    {
        $nA = count($a);
        $nB = count($b);

        if ($nA < 2 || $nB < 2) {
            return null;
        }

        $meanA = array_sum($a) / $nA;
        $meanB = array_sum($b) / $nB;
        $seA = $this->sampleVariance($a, $meanA) / $nA;
        $seB = $this->sampleVariance($b, $meanB) / $nB;
        $seSum = $seA + $seB;

        if ($seSum <= 1e-12) {
            return null;
        }

        $t = ($meanA - $meanB) / sqrt($seSum);
        $df = ($seSum ** 2) / ((($seA ** 2) / ($nA - 1)) + (($seB ** 2) / ($nB - 1)));

        return $this->studentTTwoTailedPValue($t, $df);
    }

    /**
     * Two-sided tail probability P(|T| ≥ |t|) of a Student-t distribution with `$df` degrees of freedom.
     */
    public function studentTTwoTailedPValue(float $t, float $df): float
    {
        if ($df <= 0.0 || ! is_finite($t)) {
            return is_finite($t) ? 1.0 : 0.0;
        }

        $x = $df / ($df + ($t * $t));

        return max(0.0, min(1.0, $this->regularizedIncompleteBeta($x, $df / 2.0, 0.5)));
    }

    /**
     * Regularized incomplete beta function I_x(a, b) evaluated with Lentz's continued fraction.
     */
    public function regularizedIncompleteBeta(float $x, float $a, float $b): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }

        if ($x >= 1.0) {
            return 1.0;
        }

        $front = exp(
            $this->logGamma($a + $b) - $this->logGamma($a) - $this->logGamma($b)
            + ($a * log($x)) + ($b * log(1.0 - $x)),
        );

        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $front * $this->betaContinuedFraction($x, $a, $b) / $a;
        }

        return 1.0 - ($front * $this->betaContinuedFraction(1.0 - $x, $b, $a) / $b);
    }

    /**
     * Natural log of the gamma function (Lanczos approximation, g = 7).
     */
    public function logGamma(float $x): float
    {
        if ($x < 0.5) {
            return log(M_PI / abs(sin(M_PI * $x))) - $this->logGamma(1.0 - $x);
        }

        $x -= 1.0;
        $sum = self::LANCZOS_COEFFICIENTS[0];

        for ($i = 1; $i < count(self::LANCZOS_COEFFICIENTS); $i++) {
            $sum += self::LANCZOS_COEFFICIENTS[$i] / ($x + $i);
        }

        $t = $x + 7.5;

        return (0.5 * log(2.0 * M_PI)) + (($x + 0.5) * log($t)) - $t + log($sum);
    }

    /**
     * @param  list<float>  $values
     */
    private function sampleVariance(array $values, float $mean): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return $sum / ($n - 1);
    }

    private function betaContinuedFraction(float $x, float $a, float $b): float
    {
        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - ($qab * $x / $qap);

        if (abs($d) < self::FLOAT_MIN) {
            $d = self::FLOAT_MIN;
        }

        $d = 1.0 / $d;
        $h = $d;

        for ($m = 1; $m <= self::CONTINUED_FRACTION_MAX_ITERATIONS; $m++) {
            $m2 = 2 * $m;

            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + ($aa * $d);
            $d = abs($d) < self::FLOAT_MIN ? self::FLOAT_MIN : $d;
            $c = 1.0 + ($aa / $c);
            $c = abs($c) < self::FLOAT_MIN ? self::FLOAT_MIN : $c;
            $d = 1.0 / $d;
            $h *= $d * $c;

            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + ($aa * $d);
            $d = abs($d) < self::FLOAT_MIN ? self::FLOAT_MIN : $d;
            $c = 1.0 + ($aa / $c);
            $c = abs($c) < self::FLOAT_MIN ? self::FLOAT_MIN : $c;
            $d = 1.0 / $d;
            $delta = $d * $c;
            $h *= $delta;

            if (abs($delta - 1.0) < self::CONTINUED_FRACTION_EPSILON) {
                break;
            }
        }

        return $h;
    }
}
