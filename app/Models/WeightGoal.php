<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WeightGoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider
 * @property string $start_date
 * @property string|null $end_date
 * @property int $goal_grams
 * @property string|null $source_category
 * @property CarbonImmutable|null $garmin_updated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WeightGoal extends Model
{
    /** @use HasFactory<WeightGoalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'start_date',
        'end_date',
        'goal_grams',
        'source_category',
        'garmin_updated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goal_grams' => 'integer',
            'garmin_updated_at' => 'immutable_datetime',
        ];
    }
}
