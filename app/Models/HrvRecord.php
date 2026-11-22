<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HrvRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $calendar_date
 * @property int|null $weekly_avg
 * @property int|null $last_night_avg
 * @property int|null $last_night_5min_high
 * @property string|null $status
 * @property int|null $baseline_low_upper
 * @property int|null $baseline_balanced_low
 * @property int|null $baseline_balanced_upper
 * @property string|null $feedback_phrase
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrvRecord extends Model
{
    /** @use HasFactory<HrvRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'calendar_date',
        'weekly_avg',
        'last_night_avg',
        'last_night_5min_high',
        'status',
        'baseline_low_upper',
        'baseline_balanced_low',
        'baseline_balanced_upper',
        'feedback_phrase',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekly_avg' => 'integer',
            'last_night_avg' => 'integer',
            'last_night_5min_high' => 'integer',
            'baseline_low_upper' => 'integer',
            'baseline_balanced_low' => 'integer',
            'baseline_balanced_upper' => 'integer',
        ];
    }
}
