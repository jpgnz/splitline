<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignActivityGearRequest;
use App\Http\Requests\StoreGearItemRequest;
use App\Http\Requests\UpdateGearItemRequest;
use App\Models\Activity;
use App\Models\GearItem;
use App\Services\Gear\GearService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GearController extends Controller
{
    public function index(GearService $service): Response
    {
        return Inertia::render('Gear/Index', $service->list());
    }

    public function store(StoreGearItemRequest $request): RedirectResponse
    {
        $attributes = $this->extractAttributes($request->validated());

        DB::transaction(function () use ($attributes): void {
            if ($attributes['default_sport'] !== null) {
                GearItem::query()
                    ->active()
                    ->where('default_sport', $attributes['default_sport'])
                    ->update(['default_sport' => null]);
            }

            GearItem::query()->create($attributes);
        });

        return back()->with('status', 'Gear item added.');
    }

    public function update(UpdateGearItemRequest $request, GearItem $gearItem): RedirectResponse
    {
        $attributes = $this->extractAttributes($request->validated());

        if ($gearItem->isRetired()) {
            $attributes['default_sport'] = null;
        }

        DB::transaction(function () use ($gearItem, $attributes): void {
            if ($attributes['default_sport'] !== null) {
                GearItem::query()
                    ->active()
                    ->where('default_sport', $attributes['default_sport'])
                    ->whereKeyNot($gearItem->id)
                    ->update(['default_sport' => null]);
            }

            $gearItem->update($attributes);
        });

        return back()->with('status', 'Gear item updated.');
    }

    public function toggleRetire(GearItem $gearItem): RedirectResponse
    {
        if ($gearItem->isRetired()) {
            $gearItem->update([
                'retired_at' => null,
            ]);

            return back()->with('status', 'Gear item reactivated.');
        }

        $timezone = (string) config('app.display_timezone', 'UTC');

        $gearItem->update([
            'retired_at' => CarbonImmutable::now($timezone)->format('Y-m-d'),
            'default_sport' => null,
        ]);

        return back()->with('status', 'Gear item retired.');
    }

    public function destroy(GearItem $gearItem): RedirectResponse
    {
        $gearItem->delete();

        return back()->with('status', 'Gear item deleted.');
    }

    public function assignToActivity(
        AssignActivityGearRequest $request,
        Activity $activity,
        GearService $service,
    ): RedirectResponse {
        $validated = $request->validated();
        $rawId = $validated['gear_item_id'] ?? null;
        $gearItemId = is_numeric($rawId) ? (int) $rawId : null;

        $service->assignToActivity($activity, $gearItemId);

        return back()->with(
            'status',
            $gearItemId !== null ? 'Activity gear updated.' : 'Activity gear cleared.',
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     name: string,
     *     brand: ?string,
     *     model: ?string,
     *     type: string,
     *     default_sport: ?string,
     *     initial_distance_meters: float,
     *     max_distance_meters: ?float,
     *     purchased_at: ?string,
     *     notes: ?string
     * }
     */
    private function extractAttributes(array $validated): array
    {
        $initialKm = $validated['initial_distance_km'] ?? null;
        $maxKm = $validated['max_distance_km'] ?? null;

        return [
            'name' => trim((string) $validated['name']),
            'brand' => $this->nullableTrimmedString($validated['brand'] ?? null),
            'model' => $this->nullableTrimmedString($validated['model'] ?? null),
            'type' => (string) $validated['type'],
            'default_sport' => $this->nullableTrimmedString($validated['default_sport'] ?? null),
            'initial_distance_meters' => is_numeric($initialKm) ? round((float) $initialKm * 1000.0, 1) : 0.0,
            'max_distance_meters' => is_numeric($maxKm) ? round((float) $maxKm * 1000.0, 1) : null,
            'purchased_at' => $this->nullableTrimmedString($validated['purchased_at'] ?? null),
            'notes' => $this->nullableTrimmedString($validated['notes'] ?? null),
        ];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
