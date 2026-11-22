<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SleepRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $calendar_date
 * @property int|null $sleep_seconds
 * @property int|null $deep_seconds
 * @property int|null $light_seconds
 * @property int|null $rem_seconds
 * @property int|null $awake_seconds
 * @property int|null $nap_seconds
 * @property CarbonImmutable|null $sleep_start_local
 * @property CarbonImmutable|null $sleep_end_local
 * @property int|null $sleep_score
 * @property int|null $sleep_need_minutes
 * @property int|null $next_sleep_need_minutes
 * @property array<int|string, mixed>|null $raw_payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SleepRecord extends Model
{
    /** @use HasFactory<SleepRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'calendar_date',
        'sleep_seconds',
        'deep_seconds',
        'light_seconds',
        'rem_seconds',
        'awake_seconds',
        'nap_seconds',
        'sleep_start_local',
        'sleep_end_local',
        'sleep_score',
        'sleep_need_minutes',
        'next_sleep_need_minutes',
        'raw_payload',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sleep_seconds' => 'integer',
            'deep_seconds' => 'integer',
            'light_seconds' => 'integer',
            'rem_seconds' => 'integer',
            'awake_seconds' => 'integer',
            'nap_seconds' => 'integer',
            'sleep_start_local' => 'immutable_datetime',
            'sleep_end_local' => 'immutable_datetime',
            'sleep_score' => 'integer',
            'sleep_need_minutes' => 'integer',
            'next_sleep_need_minutes' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    /**
     * Extract Garmin's overall sleep quality qualifier (e.g. EXCELLENT, GOOD, FAIR, POOR) from the raw payload.
     */
    public function qualityQualifier(): ?string
    {
        $payload = $this->raw_payload;

        if (! is_array($payload)) {
            return null;
        }

        $dto = isset($payload['dailySleepDTO']) && is_array($payload['dailySleepDTO'])
            ? $payload['dailySleepDTO']
            : $payload;

        $sleepScores = $dto['sleepScores'] ?? $payload['sleepScores'] ?? null;

        if (! is_array($sleepScores)) {
            return null;
        }

        $overall = $sleepScores['overall'] ?? null;

        if (! is_array($overall)) {
            return null;
        }

        $qualifier = $overall['qualifierKey'] ?? null;

        if (! is_string($qualifier) || trim($qualifier) === '') {
            return null;
        }

        return strtoupper(trim($qualifier));
    }
}
