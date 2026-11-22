<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FitnessAgeRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $calendar_date
 * @property float|null $chronological_age
 * @property float|null $fitness_age
 * @property float|null $previous_fitness_age
 * @property array<int|string, mixed>|null $components
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FitnessAgeRecord extends Model
{
    /** @use HasFactory<FitnessAgeRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'calendar_date',
        'chronological_age',
        'fitness_age',
        'previous_fitness_age',
        'components',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chronological_age' => 'float',
            'fitness_age' => 'float',
            'previous_fitness_age' => 'float',
            'components' => 'array',
        ];
    }
}
