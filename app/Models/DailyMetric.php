<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $calendar_date
 * @property int|null $day_duration_seconds
 * @property int|null $steps
 * @property int|null $step_goal
 * @property float|null $distance_meters
 * @property int|null $total_calories
 * @property int|null $active_calories
 * @property int|null $bmr_calories
 * @property int|null $resting_hr
 * @property int|null $seven_day_avg_rhr
 * @property int|null $min_hr
 * @property int|null $max_hr
 * @property int|null $avg_stress
 * @property int|null $max_stress
 * @property string|null $stress_qualifier
 * @property int|null $rest_stress_seconds
 * @property int|null $low_stress_seconds
 * @property int|null $medium_stress_seconds
 * @property int|null $high_stress_seconds
 * @property int|null $body_battery_current
 * @property int|null $body_battery_high
 * @property int|null $body_battery_low
 * @property int|null $body_battery_charged
 * @property int|null $body_battery_drained
 * @property int|null $body_battery_sleep
 * @property int|null $moderate_intensity_minutes
 * @property int|null $vigorous_intensity_minutes
 * @property int|null $intensity_minutes_goal
 * @property float|null $floors_ascended
 * @property float|null $floors_descended
 * @property int|null $floors_goal
 * @property float|null $avg_respiration
 * @property float|null $min_respiration
 * @property float|null $max_respiration
 * @property float|null $avg_spo2
 * @property float|null $lowest_spo2
 * @property float|null $latest_spo2
 * @property int|null $hydration_ml
 * @property array<int|string, mixed>|null $heart_rate_series
 * @property array<int|string, mixed>|null $body_battery_series
 * @property array<int|string, mixed>|null $raw_payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DailyMetric extends Model
{
    /** @use HasFactory<DailyMetricFactory> */
    use HasFactory;

    public const int STANDARD_DAY_SECONDS = 86400;

    public const int NORMAL_DAY_MIN_SECONDS = 82800;

    public const int NORMAL_DAY_MAX_SECONDS = 90000;

    public const int MIN_NORMALIZABLE_DAY_SECONDS = 14400;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'calendar_date',
        'day_duration_seconds',
        'steps',
        'step_goal',
        'distance_meters',
        'total_calories',
        'active_calories',
        'bmr_calories',
        'resting_hr',
        'seven_day_avg_rhr',
        'min_hr',
        'max_hr',
        'avg_stress',
        'max_stress',
        'stress_qualifier',
        'rest_stress_seconds',
        'low_stress_seconds',
        'medium_stress_seconds',
        'high_stress_seconds',
        'body_battery_current',
        'body_battery_high',
        'body_battery_low',
        'body_battery_charged',
        'body_battery_drained',
        'body_battery_sleep',
        'moderate_intensity_minutes',
        'vigorous_intensity_minutes',
        'intensity_minutes_goal',
        'floors_ascended',
        'floors_descended',
        'floors_goal',
        'avg_respiration',
        'min_respiration',
        'max_respiration',
        'avg_spo2',
        'lowest_spo2',
        'latest_spo2',
        'hydration_ml',
        'heart_rate_series',
        'body_battery_series',
        'raw_payload',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_duration_seconds' => 'integer',
            'steps' => 'integer',
            'step_goal' => 'integer',
            'distance_meters' => 'float',
            'total_calories' => 'integer',
            'active_calories' => 'integer',
            'bmr_calories' => 'integer',
            'resting_hr' => 'integer',
            'seven_day_avg_rhr' => 'integer',
            'min_hr' => 'integer',
            'max_hr' => 'integer',
            'avg_stress' => 'integer',
            'max_stress' => 'integer',
            'rest_stress_seconds' => 'integer',
            'low_stress_seconds' => 'integer',
            'medium_stress_seconds' => 'integer',
            'high_stress_seconds' => 'integer',
            'body_battery_current' => 'integer',
            'body_battery_high' => 'integer',
            'body_battery_low' => 'integer',
            'body_battery_charged' => 'integer',
            'body_battery_drained' => 'integer',
            'body_battery_sleep' => 'integer',
            'moderate_intensity_minutes' => 'integer',
            'vigorous_intensity_minutes' => 'integer',
            'intensity_minutes_goal' => 'integer',
            'floors_ascended' => 'float',
            'floors_descended' => 'float',
            'floors_goal' => 'integer',
            'avg_respiration' => 'float',
            'min_respiration' => 'float',
            'max_respiration' => 'float',
            'avg_spo2' => 'float',
            'lowest_spo2' => 'float',
            'latest_spo2' => 'float',
            'hydration_ml' => 'integer',
            'heart_rate_series' => 'array',
            'body_battery_series' => 'array',
            'raw_payload' => 'array',
        ];
    }

    public function dayDurationHours(): ?float
    {
        if ($this->day_duration_seconds === null || $this->day_duration_seconds <= 0) {
            return null;
        }

        return round($this->day_duration_seconds / 3600, 1);
    }

    /**
     * Determine whether this day's Garmin window was stretched or shortened by timezone travel.
     *
     * Days between 23h and 25h (including DST transitions) are treated as normal, and
     * in-progress short days on the current calendar date are never treated as travel days.
     */
    public function isTravelDay(string $todayDate): bool
    {
        $seconds = $this->day_duration_seconds;

        if ($seconds === null || $seconds <= 0) {
            return false;
        }

        if ($seconds > self::NORMAL_DAY_MAX_SECONDS) {
            return true;
        }

        return $seconds < self::NORMAL_DAY_MIN_SECONDS
            && $seconds >= self::MIN_NORMALIZABLE_DAY_SECONDS
            && $this->calendar_date < $todayDate;
    }

    public function travelDayScaleFactor(string $todayDate): float
    {
        if (! $this->isTravelDay($todayDate) || $this->day_duration_seconds === null || $this->day_duration_seconds <= 0) {
            return 1.0;
        }

        return self::STANDARD_DAY_SECONDS / $this->day_duration_seconds;
    }

    public function travelDayLabel(string $todayDate): ?string
    {
        if (! $this->isTravelDay($todayDate)) {
            return null;
        }

        $hours = $this->dayDurationHours();

        if ($hours === null) {
            return null;
        }

        $formatted = fmod($hours, 1.0) === 0.0
            ? (string) ((int) $hours)
            : number_format($hours, 1);

        return "{$formatted}h day";
    }

    /**
     * Return 24h-normalized calorie values on travel days, or raw values on normal/in-progress days.
     *
     * @return array{
     *     total: ?int,
     *     active: ?int,
     *     bmr: ?int,
     *     isNormalized: bool,
     *     rawTotal: ?int,
     *     rawActive: ?int,
     *     rawBmr: ?int,
     *     dayHours: ?float,
     *     travelDayLabel: ?string
     * }
     */
    public function normalizedCalories(string $todayDate): array
    {
        if (! $this->isTravelDay($todayDate)) {
            return [
                'total' => $this->total_calories,
                'active' => $this->active_calories,
                'bmr' => $this->bmr_calories,
                'isNormalized' => false,
                'rawTotal' => null,
                'rawActive' => null,
                'rawBmr' => null,
                'dayHours' => null,
                'travelDayLabel' => null,
            ];
        }

        $scale = $this->travelDayScaleFactor($todayDate);
        $bmr = $this->bmr_calories !== null ? (int) round($this->bmr_calories * $scale) : null;
        $active = $this->active_calories !== null ? (int) round($this->active_calories * $scale) : null;
        $total = ($bmr !== null && $active !== null)
            ? ($bmr + $active)
            : ($this->total_calories !== null ? (int) round($this->total_calories * $scale) : null);

        return [
            'total' => $total,
            'active' => $active,
            'bmr' => $bmr,
            'isNormalized' => true,
            'rawTotal' => $this->total_calories,
            'rawActive' => $this->active_calories,
            'rawBmr' => $this->bmr_calories,
            'dayHours' => $this->dayDurationHours(),
            'travelDayLabel' => $this->travelDayLabel($todayDate),
        ];
    }
}
