<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $cursor_date
 * @property int $items_synced
 * @property string|null $error_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'status',
        'started_at',
        'finished_at',
        'cursor_date',
        'items_synced',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'items_synced' => 'integer',
        ];
    }
}
