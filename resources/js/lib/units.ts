import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { KmSplitItem, SplitItem, StreamPoint } from '@/types/activity';
import type { UnitSystem } from '@/types/global';

export type { UnitSystem };

const EMPTY_VALUE = '—';

export const KM_TO_MI = 0.6213711922;
export const M_TO_FT = 3.280839895;
export const KG_TO_LB = 2.2046226218;
export const ML_TO_FLOZ = 0.0338140227;
export const MM_TO_IN = 0.0393700787;

const METERS_PER_MILE = 1609.344;
const M_PER_KM_TO_FT_PER_MI = 5.28;

function isFiniteNumber(value: number | null | undefined): value is number {
    return value !== null && value !== undefined && Number.isFinite(value);
}

function formatNumeric(value: number, decimals?: number): string {
    const options: Intl.NumberFormatOptions =
        decimals !== undefined
            ? {
                  minimumFractionDigits: decimals,
                  maximumFractionDigits: decimals,
              }
            : {};

    return new Intl.NumberFormat('en-US', options).format(value);
}

function roundTo(value: number, decimals: number): number {
    const factor = 10 ** decimals;

    return Math.round(value * factor) / factor;
}

export function distanceUnit(units: UnitSystem): 'km' | 'mi' {
    return units === 'imperial' ? 'mi' : 'km';
}

export function elevationUnit(units: UnitSystem): 'm' | 'ft' {
    return units === 'imperial' ? 'ft' : 'm';
}

export function speedUnit(units: UnitSystem): 'km/h' | 'mph' {
    return units === 'imperial' ? 'mph' : 'km/h';
}

export function paceUnit(units: UnitSystem): '/km' | '/mi' {
    return units === 'imperial' ? '/mi' : '/km';
}

export function weightUnit(units: UnitSystem): 'kg' | 'lb' {
    return units === 'imperial' ? 'lb' : 'kg';
}

export function tempUnit(units: UnitSystem): '°C' | '°F' {
    return units === 'imperial' ? '°F' : '°C';
}

export function volumeUnit(units: UnitSystem): 'ml' | 'fl oz' {
    return units === 'imperial' ? 'fl oz' : 'ml';
}

export function precipUnit(units: UnitSystem): 'mm' | 'in' {
    return units === 'imperial' ? 'in' : 'mm';
}

export function strideUnit(units: UnitSystem): 'm' | 'ft' {
    return units === 'imperial' ? 'ft' : 'm';
}

export function climbDensityUnit(units: UnitSystem): 'm/km' | 'ft/mi' {
    return units === 'imperial' ? 'ft/mi' : 'm/km';
}

export function vamUnit(units: UnitSystem): 'm/h' | 'ft/h' {
    return units === 'imperial' ? 'ft/h' : 'm/h';
}

export function oscillationUnit(units: UnitSystem): 'cm' | 'in' {
    return units === 'imperial' ? 'in' : 'cm';
}

export function oscillationFromCm(cm: number, units: UnitSystem): number {
    return units === 'imperial' ? cm / 2.54 : cm;
}

export function distanceFromMeters(meters: number, units: UnitSystem): number {
    const km = meters / 1000;

    return units === 'imperial' ? km * KM_TO_MI : km;
}

export function elevationFromMeters(meters: number, units: UnitSystem): number {
    return units === 'imperial' ? meters * M_TO_FT : meters;
}

export function speedFromMps(mps: number, units: UnitSystem): number {
    const kmh = mps * 3.6;

    return units === 'imperial' ? kmh * KM_TO_MI : kmh;
}

export function speedFromKmh(kmh: number, units: UnitSystem): number {
    return units === 'imperial' ? kmh * KM_TO_MI : kmh;
}

export function paceSecondsFromMps(
    mps: number,
    units: UnitSystem,
): number | null {
    if (!Number.isFinite(mps) || mps <= 0) {
        return null;
    }

    const metersPerUnit = units === 'imperial' ? METERS_PER_MILE : 1000;

    return metersPerUnit / mps;
}

export function weightFromKg(kg: number, units: UnitSystem): number {
    return units === 'imperial' ? kg * KG_TO_LB : kg;
}

export function tempFromC(celsius: number, units: UnitSystem): number {
    return units === 'imperial' ? (celsius * 9) / 5 + 32 : celsius;
}

export function volumeFromMl(ml: number, units: UnitSystem): number {
    return units === 'imperial' ? ml * ML_TO_FLOZ : ml;
}

export function precipFromMm(mm: number, units: UnitSystem): number {
    return units === 'imperial' ? mm * MM_TO_IN : mm;
}

export function climbDensityFromMPerKm(
    mPerKm: number,
    units: UnitSystem,
): number {
    return units === 'imperial' ? mPerKm * M_PER_KM_TO_FT_PER_MI : mPerKm;
}

export function kmToInputDistance(km: number, units: UnitSystem): number {
    const converted = units === 'imperial' ? km * KM_TO_MI : km;

    return roundTo(converted, 2);
}

export function inputDistanceToKm(value: number, units: UnitSystem): number {
    if (units === 'imperial') {
        return roundTo(value / KM_TO_MI, 3);
    }

    return value;
}

export function metersToInputElevation(
    meters: number,
    units: UnitSystem,
): number {
    return Math.round(elevationFromMeters(meters, units));
}

export function inputElevationToMeters(
    value: number,
    units: UnitSystem,
): number {
    if (units === 'imperial') {
        return roundTo(value / M_TO_FT, 1);
    }

    return value;
}

export function formatDistance(
    meters: number | null | undefined,
    units: UnitSystem,
    decimals = 2,
): string {
    if (!isFiniteNumber(meters)) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(distanceFromMeters(meters, units), decimals)} ${distanceUnit(units)}`;
}

export function formatDistanceCompact(
    meters: number | null | undefined,
    units: UnitSystem,
    decimals = 1,
): string {
    if (!isFiniteNumber(meters)) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(distanceFromMeters(meters, units), decimals)} ${distanceUnit(units)}`;
}

export function formatElevation(
    meters: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(meters)) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(Math.round(elevationFromMeters(meters, units)), 0)} ${elevationUnit(units)}`;
}

export function formatPaceSeconds(
    secondsPerUnit: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(secondsPerUnit) || secondsPerUnit <= 0) {
        return EMPTY_VALUE;
    }

    const totalSeconds = Math.round(secondsPerUnit);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')} ${paceUnit(units)}`;
}

export function formatPace(
    speedMps: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(speedMps) || speedMps <= 0) {
        return EMPTY_VALUE;
    }

    return formatPaceSeconds(paceSecondsFromMps(speedMps, units), units);
}

export function formatPace100m(speedMps: number | null | undefined): string {
    if (!isFiniteNumber(speedMps) || speedMps <= 0) {
        return EMPTY_VALUE;
    }

    const totalSeconds = Math.round(100 / speedMps);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')} /100m`;
}

export function formatSpeed(
    speedMps: number | null | undefined,
    units: UnitSystem,
    decimals = 1,
): string {
    if (!isFiniteNumber(speedMps) || speedMps < 0) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(speedFromMps(speedMps, units), decimals)} ${speedUnit(units)}`;
}

export function formatSpeedKmh(
    kmh: number | null | undefined,
    units: UnitSystem,
    decimals = 1,
): string {
    if (!isFiniteNumber(kmh)) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(speedFromKmh(kmh, units), decimals)} ${speedUnit(units)}`;
}

export function formatWeight(
    kg: number | null | undefined,
    units: UnitSystem,
    decimals = 1,
): string {
    if (!isFiniteNumber(kg) || kg < 0) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(weightFromKg(kg, units), decimals)} ${weightUnit(units)}`;
}

export function formatTemp(
    celsius: number | null | undefined,
    units: UnitSystem,
    decimals = 0,
): string {
    if (!isFiniteNumber(celsius)) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(tempFromC(celsius, units), decimals)} ${tempUnit(units)}`;
}

export function formatFluidVolume(
    ml: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(ml) || ml < 0) {
        return EMPTY_VALUE;
    }

    if (units === 'imperial') {
        return `${formatNumeric(volumeFromMl(ml, units), 1)} ${volumeUnit(units)}`;
    }

    return `${formatNumeric(Math.round(ml), 0)} ${volumeUnit(units)}`;
}

export function formatPrecip(
    mm: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(mm) || mm < 0) {
        return EMPTY_VALUE;
    }

    const decimals = units === 'imperial' ? 2 : 1;

    return `${formatNumeric(precipFromMm(mm, units), decimals)} ${precipUnit(units)}`;
}

export function formatStride(
    meters: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(meters) || meters <= 0) {
        return EMPTY_VALUE;
    }

    const normalizedMeters = meters > 10 ? meters / 100 : meters;
    const converted =
        units === 'imperial' ? normalizedMeters * M_TO_FT : normalizedMeters;

    return `${converted.toFixed(2)} ${strideUnit(units)}`;
}

export function formatClimbDensity(
    mPerKm: number | null | undefined,
    units: UnitSystem,
    decimals = 1,
): string {
    if (!isFiniteNumber(mPerKm)) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(climbDensityFromMPerKm(mPerKm, units), decimals)} ${climbDensityUnit(units)}`;
}

export function formatVam(
    metersPerHour: number | null | undefined,
    units: UnitSystem,
): string {
    if (!isFiniteNumber(metersPerHour) || metersPerHour <= 0) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(Math.round(elevationFromMeters(metersPerHour, units)), 0)} ${vamUnit(units)}`;
}

export function formatOscillation(
    cm: number | null | undefined,
    units: UnitSystem,
    decimals = 1,
): string {
    if (!isFiniteNumber(cm) || cm <= 0) {
        return EMPTY_VALUE;
    }

    return `${formatNumeric(oscillationFromCm(cm, units), decimals)} ${oscillationUnit(units)}`;
}

function finalizeImperialSplit(params: {
    index: number;
    distanceMeters: number;
    durationSeconds: number;
    startAltitude: number | null;
    endAltitude: number | null;
    elevationGain: number;
    elevationLoss: number;
    avgHr: number | null;
    avgCadence: number | null;
    avgPower: number | null;
    avgGapSpeed: number | null;
}): SplitItem {
    const safeDuration = Math.max(0, params.durationSeconds);
    const avgSpeed =
        safeDuration > 0 ? params.distanceMeters / safeDuration : 0;
    const paceSeconds = avgSpeed > 0 ? METERS_PER_MILE / avgSpeed : 0;

    const hasAltitude =
        params.startAltitude !== null &&
        params.endAltitude !== null &&
        params.distanceMeters > 0;
    const avgGrade = hasAltitude
        ? (((params.endAltitude as number) - (params.startAltitude as number)) /
              params.distanceMeters) *
          100
        : 0;

    const gapSeconds =
        params.avgGapSpeed !== null && params.avgGapSpeed > 0
            ? roundTo(METERS_PER_MILE / params.avgGapSpeed, 2)
            : null;

    return {
        index: params.index,
        label: `Mi ${params.index}`,
        distance_meters: roundTo(params.distanceMeters, 2),
        duration_seconds: roundTo(safeDuration, 2),
        avg_speed: roundTo(avgSpeed, 3),
        pace_seconds: roundTo(paceSeconds, 2),
        gap_seconds: gapSeconds,
        avg_hr: params.avgHr,
        avg_cadence: params.avgCadence,
        avg_power: params.avgPower,
        elevation_gain: roundTo(params.elevationGain, 1),
        elevation_loss: roundTo(params.elevationLoss, 1),
        avg_grade: roundTo(avgGrade, 2),
    };
}

export function computeSplitsFromSeries(
    series: StreamPoint[],
    kmSplits: KmSplitItem[],
    units: UnitSystem,
): SplitItem[] {
    if (units === 'metric') {
        return kmSplits.map((split) => ({
            index: split.km,
            label: `Km ${split.km}`,
            distance_meters: split.distance_meters,
            duration_seconds: split.duration_seconds,
            avg_speed: split.avg_speed,
            pace_seconds: split.pace_seconds_per_km,
            gap_seconds: split.gap_seconds_per_km,
            avg_hr: split.avg_hr,
            avg_cadence: split.avg_cadence,
            avg_power: split.avg_power,
            elevation_gain: split.elevation_gain,
            elevation_loss: split.elevation_loss,
            avg_grade: split.avg_grade,
        }));
    }

    const validPoints = series.filter(
        (point): point is StreamPoint & { distance: number } =>
            point.distance !== null &&
            Number.isFinite(point.distance) &&
            Number.isFinite(point.time),
    );

    const hasUsableDistance =
        validPoints.length >= 2 &&
        validPoints[validPoints.length - 1].distance -
            validPoints[0].distance >=
            100;

    if (!hasUsableDistance) {
        return kmSplits.map((split) => ({
            index: split.km,
            label: `Km ${split.km}`,
            distance_meters: split.distance_meters,
            duration_seconds: split.duration_seconds,
            avg_speed: split.avg_speed,
            pace_seconds: roundTo(split.pace_seconds_per_km / KM_TO_MI, 2),
            gap_seconds:
                split.gap_seconds_per_km !== null
                    ? roundTo(split.gap_seconds_per_km / KM_TO_MI, 2)
                    : null,
            avg_hr: split.avg_hr,
            avg_cadence: split.avg_cadence,
            avg_power: split.avg_power,
            elevation_gain: split.elevation_gain,
            elevation_loss: split.elevation_loss,
            avg_grade: split.avg_grade,
        }));
    }

    const splits: SplitItem[] = [];
    const first = validPoints[0];

    let splitIndex = 1;
    let splitStartDist = first.distance;
    let splitStartTime = first.time;
    let splitStartAlt = first.altitude;
    let nextBoundary = splitStartDist + METERS_PER_MILE;

    let prevDist = first.distance;
    let prevTime = first.time;
    let prevAlt = first.altitude;

    let elevGain = 0;
    let elevLoss = 0;
    let hrSum = 0;
    let hrCount = 0;
    let cadSum = 0;
    let cadCount = 0;
    let powerSum = 0;
    let powerCount = 0;
    let gapSpeedSum = 0;
    let gapSpeedCount = 0;

    if (isFiniteNumber(first.hr)) {
        hrSum += first.hr;
        hrCount++;
    }

    if (isFiniteNumber(first.cadence)) {
        cadSum += first.cadence;
        cadCount++;
    }

    if (isFiniteNumber(first.power)) {
        powerSum += first.power;
        powerCount++;
    }

    if (isFiniteNumber(first.gap_speed) && first.gap_speed > 0) {
        gapSpeedSum += first.gap_speed;
        gapSpeedCount++;
    }

    for (let i = 1; i < validPoints.length; i++) {
        const curr = validPoints[i];
        const currDist = curr.distance;
        const currTime = curr.time;
        const currAlt = curr.altitude;

        if (currDist < prevDist) {
            continue;
        }

        let segStartDist = prevDist;
        let segStartTime = prevTime;
        let segStartAlt = prevAlt;

        while (currDist >= nextBoundary && currDist > segStartDist) {
            const fraction =
                (nextBoundary - segStartDist) / (currDist - segStartDist);
            const crossTime =
                segStartTime + fraction * (currTime - segStartTime);
            const crossAlt =
                segStartAlt !== null && currAlt !== null
                    ? segStartAlt + fraction * (currAlt - segStartAlt)
                    : (currAlt ?? segStartAlt);

            if (segStartAlt !== null && crossAlt !== null) {
                const deltaAlt = crossAlt - segStartAlt;

                if (deltaAlt > 0) {
                    elevGain += deltaAlt;
                } else if (deltaAlt < 0) {
                    elevLoss += Math.abs(deltaAlt);
                }
            }

            if (hrCount === 0 && isFiniteNumber(curr.hr)) {
                hrSum += curr.hr;
                hrCount++;
            }

            if (cadCount === 0 && isFiniteNumber(curr.cadence)) {
                cadSum += curr.cadence;
                cadCount++;
            }

            if (powerCount === 0 && isFiniteNumber(curr.power)) {
                powerSum += curr.power;
                powerCount++;
            }

            if (
                gapSpeedCount === 0 &&
                isFiniteNumber(curr.gap_speed) &&
                curr.gap_speed > 0
            ) {
                gapSpeedSum += curr.gap_speed;
                gapSpeedCount++;
            }

            splits.push(
                finalizeImperialSplit({
                    index: splitIndex,
                    distanceMeters: nextBoundary - splitStartDist,
                    durationSeconds: crossTime - splitStartTime,
                    startAltitude: splitStartAlt,
                    endAltitude: crossAlt,
                    elevationGain: elevGain,
                    elevationLoss: elevLoss,
                    avgHr: hrCount > 0 ? Math.round(hrSum / hrCount) : null,
                    avgCadence:
                        cadCount > 0 ? Math.round(cadSum / cadCount) : null,
                    avgPower:
                        powerCount > 0
                            ? Math.round(powerSum / powerCount)
                            : null,
                    avgGapSpeed:
                        gapSpeedCount > 0 ? gapSpeedSum / gapSpeedCount : null,
                }),
            );

            splitIndex++;
            splitStartDist = nextBoundary;
            splitStartTime = crossTime;
            splitStartAlt = crossAlt;
            nextBoundary += METERS_PER_MILE;

            segStartDist = splitStartDist;
            segStartTime = crossTime;
            segStartAlt = crossAlt;

            elevGain = 0;
            elevLoss = 0;
            hrSum = 0;
            hrCount = 0;
            cadSum = 0;
            cadCount = 0;
            powerSum = 0;
            powerCount = 0;
            gapSpeedSum = 0;
            gapSpeedCount = 0;
        }

        if (segStartAlt !== null && currAlt !== null) {
            const deltaAlt = currAlt - segStartAlt;

            if (deltaAlt > 0) {
                elevGain += deltaAlt;
            } else if (deltaAlt < 0) {
                elevLoss += Math.abs(deltaAlt);
            }
        }

        if (splitStartAlt === null && currAlt !== null) {
            splitStartAlt = currAlt;
        }

        if (isFiniteNumber(curr.hr)) {
            hrSum += curr.hr;
            hrCount++;
        }

        if (isFiniteNumber(curr.cadence)) {
            cadSum += curr.cadence;
            cadCount++;
        }

        if (isFiniteNumber(curr.power)) {
            powerSum += curr.power;
            powerCount++;
        }

        if (isFiniteNumber(curr.gap_speed) && curr.gap_speed > 0) {
            gapSpeedSum += curr.gap_speed;
            gapSpeedCount++;
        }

        prevDist = currDist;
        prevTime = currTime;
        prevAlt = currAlt;
    }

    const remainingDistance = prevDist - splitStartDist;

    if (remainingDistance >= 100) {
        splits.push(
            finalizeImperialSplit({
                index: splitIndex,
                distanceMeters: remainingDistance,
                durationSeconds: prevTime - splitStartTime,
                startAltitude: splitStartAlt,
                endAltitude: prevAlt,
                elevationGain: elevGain,
                elevationLoss: elevLoss,
                avgHr: hrCount > 0 ? Math.round(hrSum / hrCount) : null,
                avgCadence: cadCount > 0 ? Math.round(cadSum / cadCount) : null,
                avgPower:
                    powerCount > 0 ? Math.round(powerSum / powerCount) : null,
                avgGapSpeed:
                    gapSpeedCount > 0 ? gapSpeedSum / gapSpeedCount : null,
            }),
        );
    }

    return splits;
}

export function useUnits() {
    const page = usePage();

    const units = computed<UnitSystem>(() =>
        page.props.units === 'imperial' ? 'imperial' : 'metric',
    );
    const isImperial = computed<boolean>(() => units.value === 'imperial');
    const isMetric = computed<boolean>(() => units.value === 'metric');

    return {
        units,
        isImperial,
        isMetric,
        distanceUnit: computed(() => distanceUnit(units.value)),
        elevationUnit: computed(() => elevationUnit(units.value)),
        speedUnit: computed(() => speedUnit(units.value)),
        paceUnit: computed(() => paceUnit(units.value)),
        weightUnit: computed(() => weightUnit(units.value)),
        tempUnit: computed(() => tempUnit(units.value)),
        volumeUnit: computed(() => volumeUnit(units.value)),
        precipUnit: computed(() => precipUnit(units.value)),
        strideUnit: computed(() => strideUnit(units.value)),
        climbDensityUnit: computed(() => climbDensityUnit(units.value)),
        vamUnit: computed(() => vamUnit(units.value)),
        oscillationUnit: computed(() => oscillationUnit(units.value)),
        distanceFromMeters: (meters: number) =>
            distanceFromMeters(meters, units.value),
        elevationFromMeters: (meters: number) =>
            elevationFromMeters(meters, units.value),
        speedFromMps: (mps: number) => speedFromMps(mps, units.value),
        speedFromKmh: (kmh: number) => speedFromKmh(kmh, units.value),
        paceSecondsFromMps: (mps: number) =>
            paceSecondsFromMps(mps, units.value),
        weightFromKg: (kg: number) => weightFromKg(kg, units.value),
        tempFromC: (celsius: number) => tempFromC(celsius, units.value),
        volumeFromMl: (ml: number) => volumeFromMl(ml, units.value),
        precipFromMm: (mm: number) => precipFromMm(mm, units.value),
        climbDensityFromMPerKm: (mPerKm: number) =>
            climbDensityFromMPerKm(mPerKm, units.value),
        oscillationFromCm: (cm: number) => oscillationFromCm(cm, units.value),
        kmToInputDistance: (km: number) => kmToInputDistance(km, units.value),
        inputDistanceToKm: (value: number) =>
            inputDistanceToKm(value, units.value),
        metersToInputElevation: (meters: number) =>
            metersToInputElevation(meters, units.value),
        inputElevationToMeters: (value: number) =>
            inputElevationToMeters(value, units.value),
        formatDistance: (meters: number | null | undefined, decimals = 2) =>
            formatDistance(meters, units.value, decimals),
        formatDistanceCompact: (
            meters: number | null | undefined,
            decimals = 1,
        ) => formatDistanceCompact(meters, units.value, decimals),
        formatElevation: (meters: number | null | undefined) =>
            formatElevation(meters, units.value),
        formatPace: (speedMps: number | null | undefined) =>
            formatPace(speedMps, units.value),
        formatPaceSeconds: (secondsPerUnit: number | null | undefined) =>
            formatPaceSeconds(secondsPerUnit, units.value),
        formatPace100m,
        formatSpeed: (speedMps: number | null | undefined, decimals = 1) =>
            formatSpeed(speedMps, units.value, decimals),
        formatSpeedKmh: (kmh: number | null | undefined, decimals = 1) =>
            formatSpeedKmh(kmh, units.value, decimals),
        formatWeight: (kg: number | null | undefined, decimals = 1) =>
            formatWeight(kg, units.value, decimals),
        formatTemp: (celsius: number | null | undefined, decimals = 0) =>
            formatTemp(celsius, units.value, decimals),
        formatFluidVolume: (ml: number | null | undefined) =>
            formatFluidVolume(ml, units.value),
        formatPrecip: (mm: number | null | undefined) =>
            formatPrecip(mm, units.value),
        formatStride: (meters: number | null | undefined) =>
            formatStride(meters, units.value),
        formatClimbDensity: (mPerKm: number | null | undefined, decimals = 1) =>
            formatClimbDensity(mPerKm, units.value, decimals),
        formatVam: (metersPerHour: number | null | undefined) =>
            formatVam(metersPerHour, units.value),
        formatOscillation: (cm: number | null | undefined, decimals = 1) =>
            formatOscillation(cm, units.value, decimals),
        computeSplitsFromSeries: (
            series: StreamPoint[],
            kmSplits: KmSplitItem[],
        ) => computeSplitsFromSeries(series, kmSplits, units.value),
    };
}
