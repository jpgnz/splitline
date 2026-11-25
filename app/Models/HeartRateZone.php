<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HeartRateZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $sport
 * @property string|null $training_method
 * @property int|null $max_hr
 * @property int|null $resting_hr
 * @property int|null $lactate_threshold_hr
 * @property int|null $zone1_floor
 * @property int|null $zone2_floor
 * @property int|null $zone3_floor
 * @property int|null $zone4_floor
 * @property int|null $zone5_floor
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HeartRateZone extends Model
{
    /** @use HasFactory<HeartRateZoneFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'sport',
        'training_method',
        'max_hr',
        'resting_hr',
        'lactate_threshold_hr',
        'zone1_floor',
        'zone2_floor',
        'zone3_floor',
        'zone4_floor',
        'zone5_floor',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_hr' => 'integer',
            'resting_hr' => 'integer',
            'lactate_threshold_hr' => 'integer',
            'zone1_floor' => 'integer',
            'zone2_floor' => 'integer',
            'zone3_floor' => 'integer',
            'zone4_floor' => 'integer',
            'zone5_floor' => 'integer',
        ];
    }
}
