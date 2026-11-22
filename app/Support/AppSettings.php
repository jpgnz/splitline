<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;

class AppSettings
{
    public const string UNIT_METRIC = 'metric';

    public const string UNIT_IMPERIAL = 'imperial';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    private ?bool $tableExists = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            return $default;
        }

        return $all[$key];
    }

    public function set(string $key, mixed $value): void
    {
        if (! $this->hasTable()) {
            return;
        }

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );

        if ($this->memo !== null) {
            $this->memo[$key] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function forget(string $key): void
    {
        if (! $this->hasTable()) {
            return;
        }

        AppSetting::query()->where('key', $key)->delete();

        if ($this->memo !== null) {
            unset($this->memo[$key]);
        }
    }

    public function flushMemo(): void
    {
        $this->memo = null;
        $this->tableExists = null;
    }

    /**
     * @return 'metric'|'imperial'
     */
    public function unitSystem(): string
    {
        $configured = $this->get('unit_system');

        if ($configured === self::UNIT_IMPERIAL || $configured === self::UNIT_METRIC) {
            return $configured;
        }

        $envDefault = strtolower((string) config('app.default_units', self::UNIT_METRIC));

        return $envDefault === self::UNIT_IMPERIAL ? self::UNIT_IMPERIAL : self::UNIT_METRIC;
    }

    public function isImperial(): bool
    {
        return $this->unitSystem() === self::UNIT_IMPERIAL;
    }

    public function displayTimezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        if (! $this->hasTable()) {
            return $this->memo = [];
        }

        /** @var array<string, mixed> $rows */
        $rows = AppSetting::query()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (AppSetting $setting): array => [$setting->key => $setting->value])
            ->all();

        return $this->memo = $rows;
    }

    private function hasTable(): bool
    {
        if ($this->tableExists === null) {
            $this->tableExists = Schema::hasTable('app_settings');
        }

        return $this->tableExists;
    }
}
