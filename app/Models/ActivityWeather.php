<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityWeatherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-type WeatherSample array{
 *     time_offset_s: int,
 *     distance_m: float,
 *     lat: float,
 *     lng: float,
 *     wmo_code: ?int,
 *     temperature_c: ?float,
 *     apparent_temperature_c: ?float,
 *     humidity_pct: ?int,
 *     dew_point_c: ?float,
 *     wind_speed_kmh: ?float,
 *     wind_gusts_kmh: ?float,
 *     wind_direction_deg: ?int,
 *     wind_direction_compass: ?string,
 *     precipitation_mm: ?float,
 *     cloud_cover_pct: ?int,
 *     surface_pressure_hpa: ?float
 * }
 *
 * @property int $id
 * @property int $activity_id
 * @property string $provider
 * @property string $model_endpoint
 * @property string $sampling_mode
 * @property int $waypoint_count
 * @property int|null $wmo_code
 * @property string $condition
 * @property string|null $base_condition
 * @property string $icon
 * @property float|null $temperature_c
 * @property float|null $min_temperature_c
 * @property float|null $max_temperature_c
 * @property float|null $apparent_temperature_c
 * @property int|null $humidity_pct
 * @property float|null $dew_point_c
 * @property float|null $wind_speed_kmh
 * @property float|null $wind_gusts_kmh
 * @property int|null $wind_direction_deg
 * @property string|null $wind_direction_compass
 * @property float|null $precipitation_mm
 * @property int|null $cloud_cover_pct
 * @property float|null $surface_pressure_hpa
 * @property float|null $headwind_pct
 * @property float|null $crosswind_pct
 * @property float|null $tailwind_pct
 * @property float|null $avg_effective_wind_kmh
 * @property list<WeatherSample> $samples
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity $activity
 */
class ActivityWeather extends Model
{
    /** @use HasFactory<ActivityWeatherFactory> */
    use HasFactory;

    protected $table = 'activity_weathers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'provider',
        'model_endpoint',
        'sampling_mode',
        'waypoint_count',
        'wmo_code',
        'condition',
        'base_condition',
        'icon',
        'temperature_c',
        'min_temperature_c',
        'max_temperature_c',
        'apparent_temperature_c',
        'humidity_pct',
        'dew_point_c',
        'wind_speed_kmh',
        'wind_gusts_kmh',
        'wind_direction_deg',
        'wind_direction_compass',
        'precipitation_mm',
        'cloud_cover_pct',
        'surface_pressure_hpa',
        'headwind_pct',
        'crosswind_pct',
        'tailwind_pct',
        'avg_effective_wind_kmh',
        'samples',
        'fetched_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_id' => 'integer',
            'waypoint_count' => 'integer',
            'wmo_code' => 'integer',
            'temperature_c' => 'float',
            'min_temperature_c' => 'float',
            'max_temperature_c' => 'float',
            'apparent_temperature_c' => 'float',
            'humidity_pct' => 'integer',
            'dew_point_c' => 'float',
            'wind_speed_kmh' => 'float',
            'wind_gusts_kmh' => 'float',
            'wind_direction_deg' => 'integer',
            'precipitation_mm' => 'float',
            'cloud_cover_pct' => 'integer',
            'surface_pressure_hpa' => 'float',
            'headwind_pct' => 'float',
            'crosswind_pct' => 'float',
            'tailwind_pct' => 'float',
            'avg_effective_wind_kmh' => 'float',
            'samples' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
