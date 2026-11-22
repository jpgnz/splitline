<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityStreamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $activity_id
 * @property array<int|string, mixed>|null $series
 * @property array<int|string, mixed>|null $polyline
 * @property array<int|string, mixed>|null $laps
 * @property array<int|string, mixed>|null $km_splits
 * @property array<int|string, mixed>|null $run_walk_segments
 * @property array<int|string, mixed>|null $jumps
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity $activity
 */
class ActivityStream extends Model
{
    /** @use HasFactory<ActivityStreamFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'series',
        'polyline',
        'laps',
        'km_splits',
        'run_walk_segments',
        'jumps',
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
            'series' => 'array',
            'polyline' => 'array',
            'laps' => 'array',
            'km_splits' => 'array',
            'run_walk_segments' => 'array',
            'jumps' => 'array',
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
