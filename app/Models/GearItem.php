<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GearItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $brand
 * @property string|null $model
 * @property string $type
 * @property string|null $default_sport
 * @property float $initial_distance_meters
 * @property float|null $max_distance_meters
 * @property string|null $purchased_at
 * @property string|null $retired_at
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Activity> $activities
 */
class GearItem extends Model
{
    /** @use HasFactory<GearItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'brand',
        'model',
        'type',
        'default_sport',
        'initial_distance_meters',
        'max_distance_meters',
        'purchased_at',
        'retired_at',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'initial_distance_meters' => 'float',
            'max_distance_meters' => 'float',
        ];
    }

    /**
     * @return BelongsToMany<Activity, $this>
     */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_gear')->withTimestamps();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->whereNotNull('retired_at');
    }
}
