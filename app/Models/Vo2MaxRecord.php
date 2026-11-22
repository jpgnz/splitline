<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Vo2MaxRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $calendar_date
 * @property string $sport
 * @property float|null $vo2_max_precise
 * @property float|null $vo2_max
 * @property string|null $sub_sport
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Vo2MaxRecord extends Model
{
    /** @use HasFactory<Vo2MaxRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'calendar_date',
        'sport',
        'vo2_max_precise',
        'vo2_max',
        'sub_sport',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vo2_max_precise' => 'float',
            'vo2_max' => 'float',
        ];
    }
}
