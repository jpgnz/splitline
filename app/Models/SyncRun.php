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
 * @property string|null $until_date
 * @property bool $initial_setup_completed
 * @property int $items_synced
 * @property string|null $error_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    public const int ACTIVE_WINDOW_MINUTES = 10;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'status',
        'started_at',
        'finished_at',
        'cursor_date',
        'until_date',
        'initial_setup_completed',
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
            'initial_setup_completed' => 'boolean',
            'items_synced' => 'integer',
        ];
    }

    public function isActive(?CarbonImmutable $now = null): bool
    {
        if (! in_array($this->status, ['running', 'queued'], true)) {
            return false;
        }

        $cutoff = ($now ?? CarbonImmutable::now())->subMinutes(self::ACTIVE_WINDOW_MINUTES);

        if ($this->updated_at !== null && $this->updated_at->greaterThanOrEqualTo($cutoff)) {
            return true;
        }

        return $this->started_at->greaterThanOrEqualTo($cutoff);
    }

    public function isStale(?CarbonImmutable $now = null): bool
    {
        return in_array($this->status, ['running', 'queued'], true) && ! $this->isActive($now);
    }

    public function effectiveStatus(?CarbonImmutable $now = null): string
    {
        if ($this->isStale($now)) {
            return 'interrupted';
        }

        return (string) $this->status;
    }

    public function isResumableBackfill(?CarbonImmutable $now = null): bool
    {
        return $this->type === 'backfill'
            && ! $this->isActive($now)
            && in_array($this->status, ['failed', 'running', 'queued'], true)
            && is_string($this->cursor_date)
            && $this->cursor_date !== '';
    }

    public static function supersedeStaleBackfills(?CarbonImmutable $now = null): int
    {
        $timestamp = $now ?? CarbonImmutable::now();
        $cutoff = $timestamp->subMinutes(self::ACTIVE_WINDOW_MINUTES);

        return self::query()
            ->where('type', 'backfill')
            ->whereIn('status', ['running', 'queued'])
            ->where('started_at', '<', $cutoff)
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->whereNull('updated_at')
                    ->orWhere('updated_at', '<', $cutoff);
            })
            ->update([
                'status' => 'interrupted',
                'finished_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    public static function findActive(?CarbonImmutable $now = null): ?self
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subMinutes(self::ACTIVE_WINDOW_MINUTES);

        return self::query()
            ->whereIn('status', ['running', 'queued'])
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('updated_at', '>=', $cutoff)
                    ->orWhere('started_at', '>=', $cutoff);
            })
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    public static function findLatestForStatus(?CarbonImmutable $now = null): ?self
    {
        $active = self::findActive($now);

        if ($active !== null) {
            return $active;
        }

        return self::query()
            ->latest('started_at')
            ->latest('id')
            ->first();
    }
}
