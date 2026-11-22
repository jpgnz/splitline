<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WeightMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $garmin_sample_pk
 * @property string $calendar_date
 * @property CarbonImmutable|null $measured_at
 * @property int $weight_grams
 * @property float|null $bmi
 * @property float|null $body_fat_pct
 * @property float|null $body_water_pct
 * @property int|null $bone_mass_grams
 * @property int|null $muscle_mass_grams
 * @property string|null $source_type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WeightMeasurement extends Model
{
    /** @use HasFactory<WeightMeasurementFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'garmin_sample_pk',
        'calendar_date',
        'measured_at',
        'weight_grams',
        'bmi',
        'body_fat_pct',
        'body_water_pct',
        'bone_mass_grams',
        'muscle_mass_grams',
        'source_type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'garmin_sample_pk' => 'integer',
            'measured_at' => 'immutable_datetime',
            'weight_grams' => 'integer',
            'bmi' => 'float',
            'body_fat_pct' => 'float',
            'body_water_pct' => 'float',
            'bone_mass_grams' => 'integer',
            'muscle_mass_grams' => 'integer',
        ];
    }
}
