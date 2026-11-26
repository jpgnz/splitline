import {
    computeEffectiveWind,
    computeSegmentBearing,
    interpolateWeatherAt,
} from '@/lib/wind';
import type { ActivityWeatherPayload, StreamPoint } from '@/types/activity';

export type IntervalDetectionMetric = 'power' | 'gap_speed' | 'speed';

export interface StreamSelectionStats {
    startIndex: number;
    endIndex: number;
    pointCount: number;
    startTime: number;
    endTime: number;
    durationSeconds: number;
    startDistanceMeters: number | null;
    endDistanceMeters: number | null;
    distanceMeters: number | null;
    avgSpeed: number | null;
    maxSpeed: number | null;
    avgGapSpeed: number | null;
    elevationGain: number | null;
    elevationLoss: number | null;
    netElevation: number | null;
    avgGradePct: number | null;
    vam: number | null;
    avgHr: number | null;
    maxHr: number | null;
    minHr: number | null;
    avgPower: number | null;
    maxPower: number | null;
    normalizedPower: number | null;
    avgCadence: number | null;
    avgEffectiveWindKmh: number | null;
}

export interface DetectedInterval {
    id: string;
    index: number;
    label: string;
    shortLabel: string;
    metric: IntervalDetectionMetric;
    startIndex: number;
    endIndex: number;
    intensityRatio: number;
    stats: StreamSelectionStats;
}

function isFiniteNumber(value: number | null | undefined): value is number {
    return typeof value === 'number' && Number.isFinite(value);
}

function roundTo(value: number, decimals: number): number {
    const factor = 10 ** decimals;

    return Math.round(value * factor) / factor;
}

function medianOf(values: number[]): number | null {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);

    if (sorted.length % 2 === 1) {
        return sorted[mid];
    }

    return (sorted[mid - 1] + sorted[mid]) / 2;
}

function approxMetersBetween(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const dLat = (lat2 - lat1) * 111_320;
    const avgLatRad = (((lat1 + lat2) / 2) * Math.PI) / 180;
    const dLng = (lng2 - lng1) * 111_320 * Math.cos(avgLatRad);

    return Math.hypot(dLat, dLng);
}

/**
 * Standard Coggan 30-second rolling-average 4th-power Normalized Power (NP).
 * Resamples irregularly spaced telemetry onto a 1-second grid while resetting
 * across pause gaps (> 15s) so cafe stops never distort NP.
 */
function computeNormalizedPower(
    series: StreamPoint[],
    startIdx: number,
    endIdx: number,
    durationSeconds: number,
): number | null {
    if (durationSeconds < 30) {
        return null;
    }

    const ring = new Float64Array(30);
    let ringPos = 0;
    let ringCount = 0;
    let ringSum = 0;
    let fourthSum = 0;
    let fourthCount = 0;
    let hasPositivePower = false;

    const pushSecond = (watts: number): void => {
        if (ringCount === 30) {
            ringSum -= ring[ringPos];
        } else {
            ringCount += 1;
        }

        ring[ringPos] = watts;
        ringSum += watts;
        ringPos = (ringPos + 1) % 30;

        if (ringCount === 30) {
            const avg30 = ringSum / 30;

            fourthSum += avg30 ** 4;
            fourthCount += 1;
        }
    };

    let prevTime: number | null = null;
    let prevPower: number | null = null;

    for (let i = startIdx; i <= endIdx; i += 1) {
        const pt = series[i];

        if (!isFiniteNumber(pt.power) || pt.power < 0) {
            continue;
        }

        if (pt.power > 0) {
            hasPositivePower = true;
        }

        if (prevTime === null || prevPower === null) {
            pushSecond(pt.power);
            prevTime = pt.time;
            prevPower = pt.power;

            continue;
        }

        const dt = Math.round(pt.time - prevTime);

        if (dt <= 0) {
            continue;
        }

        if (dt > 15) {
            ringPos = 0;
            ringCount = 0;
            ringSum = 0;
            pushSecond(pt.power);
        } else {
            for (let step = 1; step <= dt; step += 1) {
                const interpolated =
                    prevPower + ((pt.power - prevPower) * step) / dt;

                pushSecond(interpolated);
            }
        }

        prevTime = pt.time;
        prevPower = pt.power;
    }

    if (!hasPositivePower || fourthCount === 0) {
        return null;
    }

    return Math.round((fourthSum / fourthCount) ** 0.25);
}

function computeAverageEffectiveWind(
    series: StreamPoint[],
    startIdx: number,
    endIdx: number,
    weather: ActivityWeatherPayload | null | undefined,
): number | null {
    const samples = weather?.samples;

    if (!samples || samples.length === 0) {
        return null;
    }

    let anchor: StreamPoint | null = null;
    let effSum = 0;
    let weightSum = 0;

    for (let i = startIdx; i <= endIdx; i += 1) {
        const pt = series[i];

        if (!isFiniteNumber(pt.lat) || !isFiniteNumber(pt.lng)) {
            continue;
        }

        if (!anchor) {
            anchor = pt;

            continue;
        }

        const movedMeters = approxMetersBetween(
            anchor.lat as number,
            anchor.lng as number,
            pt.lat,
            pt.lng,
        );

        if (movedMeters < 5) {
            continue;
        }

        const interpolated = interpolateWeatherAt(
            samples,
            pt.time,
            pt.distance,
        );

        if (
            interpolated &&
            isFiniteNumber(interpolated.wind_speed_kmh) &&
            isFiniteNumber(interpolated.wind_direction_deg)
        ) {
            const bearing = computeSegmentBearing(
                anchor.lat as number,
                anchor.lng as number,
                pt.lat,
                pt.lng,
            );
            const effective = computeEffectiveWind(
                interpolated.wind_speed_kmh,
                interpolated.wind_direction_deg,
                bearing,
            );
            const dt = Math.max(1, Math.min(30, pt.time - anchor.time));

            effSum += effective.effectiveKmh * dt;
            weightSum += dt;
        }

        anchor = pt;
    }

    return weightSum > 0 ? roundTo(effSum / weightSum, 1) : null;
}

/**
 * Compute comprehensive summary metrics for any [startIdx, endIdx] slice of an activity stream.
 */
export function computeStreamSelectionStats(
    series: StreamPoint[],
    startIdx: number,
    endIdx: number,
    weather?: ActivityWeatherPayload | null,
): StreamSelectionStats | null {
    if (series.length === 0) {
        return null;
    }

    const clampedStart = Math.max(
        0,
        Math.min(series.length - 1, Math.floor(startIdx)),
    );
    const clampedEnd = Math.max(
        0,
        Math.min(series.length - 1, Math.floor(endIdx)),
    );
    const start = Math.min(clampedStart, clampedEnd);
    const end = Math.max(clampedStart, clampedEnd);

    const startPoint = series[start];
    const endPoint = series[end];
    const startTime = startPoint.time;
    const endTime = endPoint.time;
    const durationSeconds = Math.max(0, endTime - startTime);

    const positiveSteps: number[] = [];

    for (let i = start + 1; i <= end; i += 1) {
        const dt = series[i].time - series[i - 1].time;

        if (Number.isFinite(dt) && dt > 0 && dt <= 30) {
            positiveSteps.push(dt);
        }
    }

    const medianStep = medianOf(positiveSteps) ?? 1;

    let startDistanceMeters: number | null = null;
    let endDistanceMeters: number | null = null;

    let speedWeightedSum = 0;
    let speedWeightSum = 0;
    let maxSpeed: number | null = null;

    let gapWeightedSum = 0;
    let gapWeightSum = 0;

    let firstAlt: number | null = null;
    let lastAlt: number | null = null;
    let prevAlt: number | null = null;
    let altCount = 0;
    let elevGain = 0;
    let elevLoss = 0;

    let gradeWeightedSum = 0;
    let gradeWeightSum = 0;

    let hrWeightedSum = 0;
    let hrWeightSum = 0;
    let maxHr: number | null = null;
    let minHr: number | null = null;

    let powerWeightedSum = 0;
    let powerWeightSum = 0;
    let maxPower: number | null = null;
    let hasPositivePower = false;

    let cadenceWeightedSum = 0;
    let cadenceWeightSum = 0;

    for (let i = start; i <= end; i += 1) {
        const pt = series[i];
        const rawDt =
            i === start
                ? end > start
                    ? series[start + 1].time - pt.time
                    : 1
                : pt.time - series[i - 1].time;
        const dt =
            Number.isFinite(rawDt) && rawDt > 0 && rawDt <= 15
                ? rawDt
                : medianStep;

        if (isFiniteNumber(pt.distance)) {
            if (startDistanceMeters === null) {
                startDistanceMeters = pt.distance;
            }

            endDistanceMeters = pt.distance;
        }

        if (isFiniteNumber(pt.speed) && pt.speed >= 0) {
            speedWeightedSum += pt.speed * dt;
            speedWeightSum += dt;

            if (maxSpeed === null || pt.speed > maxSpeed) {
                maxSpeed = pt.speed;
            }
        }

        if (isFiniteNumber(pt.gap_speed) && pt.gap_speed > 0) {
            gapWeightedSum += pt.gap_speed * dt;
            gapWeightSum += dt;
        }

        if (isFiniteNumber(pt.altitude)) {
            if (firstAlt === null) {
                firstAlt = pt.altitude;
            }

            if (prevAlt !== null) {
                const deltaAlt = pt.altitude - prevAlt;

                if (deltaAlt > 0) {
                    elevGain += deltaAlt;
                } else if (deltaAlt < 0) {
                    elevLoss += Math.abs(deltaAlt);
                }
            }

            prevAlt = pt.altitude;
            lastAlt = pt.altitude;
            altCount += 1;
        }

        if (isFiniteNumber(pt.grade)) {
            gradeWeightedSum += pt.grade * dt;
            gradeWeightSum += dt;
        }

        if (isFiniteNumber(pt.hr) && pt.hr > 0) {
            hrWeightedSum += pt.hr * dt;
            hrWeightSum += dt;

            if (maxHr === null || pt.hr > maxHr) {
                maxHr = pt.hr;
            }

            if (minHr === null || pt.hr < minHr) {
                minHr = pt.hr;
            }
        }

        if (isFiniteNumber(pt.power) && pt.power >= 0) {
            powerWeightedSum += pt.power * dt;
            powerWeightSum += dt;

            if (pt.power > 0) {
                hasPositivePower = true;
            }

            if (maxPower === null || pt.power > maxPower) {
                maxPower = pt.power;
            }
        }

        if (isFiniteNumber(pt.cadence) && pt.cadence > 0) {
            cadenceWeightedSum += pt.cadence * dt;
            cadenceWeightSum += dt;
        }
    }

    const distanceMeters =
        startDistanceMeters !== null &&
        endDistanceMeters !== null &&
        endDistanceMeters >= startDistanceMeters
            ? roundTo(endDistanceMeters - startDistanceMeters, 2)
            : null;

    const avgSpeed =
        distanceMeters !== null && distanceMeters > 0 && durationSeconds > 0
            ? roundTo(distanceMeters / durationSeconds, 3)
            : speedWeightSum > 0
              ? roundTo(speedWeightedSum / speedWeightSum, 3)
              : null;

    const avgGapSpeed =
        gapWeightSum > 0 ? roundTo(gapWeightedSum / gapWeightSum, 3) : null;

    const elevationGain = altCount >= 2 ? roundTo(elevGain, 1) : null;
    const elevationLoss = altCount >= 2 ? roundTo(elevLoss, 1) : null;
    const netElevation =
        altCount >= 2 && firstAlt !== null && lastAlt !== null
            ? roundTo(lastAlt - firstAlt, 1)
            : null;

    const avgGradePct =
        netElevation !== null && distanceMeters !== null && distanceMeters >= 10
            ? roundTo((netElevation / distanceMeters) * 100, 1)
            : gradeWeightSum > 0
              ? roundTo(gradeWeightedSum / gradeWeightSum, 1)
              : null;

    const vam =
        elevationGain !== null && elevationGain >= 10 && durationSeconds >= 30
            ? Math.round((elevationGain / durationSeconds) * 3600)
            : null;

    const avgHr =
        hrWeightSum > 0 ? Math.round(hrWeightedSum / hrWeightSum) : null;
    const avgPower =
        hasPositivePower && powerWeightSum > 0
            ? Math.round(powerWeightedSum / powerWeightSum)
            : null;
    const normalizedPower = hasPositivePower
        ? computeNormalizedPower(series, start, end, durationSeconds)
        : null;
    const avgCadence =
        cadenceWeightSum > 0
            ? Math.round(cadenceWeightedSum / cadenceWeightSum)
            : null;

    const avgEffectiveWindKmh = computeAverageEffectiveWind(
        series,
        start,
        end,
        weather,
    );

    return {
        startIndex: start,
        endIndex: end,
        pointCount: end - start + 1,
        startTime,
        endTime,
        durationSeconds: roundTo(durationSeconds, 1),
        startDistanceMeters:
            startDistanceMeters !== null
                ? roundTo(startDistanceMeters, 2)
                : null,
        endDistanceMeters:
            endDistanceMeters !== null ? roundTo(endDistanceMeters, 2) : null,
        distanceMeters,
        avgSpeed,
        maxSpeed: maxSpeed !== null ? roundTo(maxSpeed, 3) : null,
        avgGapSpeed,
        elevationGain,
        elevationLoss,
        netElevation,
        avgGradePct,
        vam,
        avgHr,
        maxHr: maxHr !== null ? Math.round(maxHr) : null,
        minHr: minHr !== null ? Math.round(minHr) : null,
        avgPower,
        maxPower:
            hasPositivePower && maxPower !== null ? Math.round(maxPower) : null,
        normalizedPower,
        avgCadence,
        avgEffectiveWindKmh,
    };
}

function smoothByTimeWindow(
    times: number[],
    values: Array<number | null>,
    halfWindowSeconds = 4,
): Array<number | null> {
    const n = values.length;
    const smoothed: Array<number | null> = Array.from(
        { length: n },
        (): number | null => null,
    );

    let left = 0;
    let right = 0;
    let sum = 0;
    let count = 0;

    for (let i = 0; i < n; i += 1) {
        const t = times[i];

        while (right < n && times[right] <= t + halfWindowSeconds) {
            const v = values[right];

            if (v !== null) {
                sum += v;
                count += 1;
            }

            right += 1;
        }

        while (left < right && times[left] < t - halfWindowSeconds) {
            const v = values[left];

            if (v !== null) {
                sum -= v;
                count -= 1;
            }

            left += 1;
        }

        smoothed[i] = count > 0 ? sum / count : null;
    }

    return smoothed;
}

/**
 * Auto-detect meaningful sustained high-intensity work intervals or surges.
 * Uses power when available on >= 50% of samples, otherwise GAP speed or speed.
 */
export function detectWorkIntervals(
    series: StreamPoint[],
    isCycling: boolean,
    weather?: ActivityWeatherPayload | null,
): DetectedInterval[] {
    if (series.length < 20) {
        return [];
    }

    const totalDuration = series[series.length - 1].time - series[0].time;

    if (!Number.isFinite(totalDuration) || totalDuration < 120) {
        return [];
    }

    const minCoverageCount = Math.ceil(series.length * 0.5);
    const powerValidCount = series.filter(
        (p) => isFiniteNumber(p.power) && p.power > 0,
    ).length;
    const gapValidCount = series.filter(
        (p) => isFiniteNumber(p.gap_speed) && p.gap_speed > 0.5,
    ).length;
    const speedValidCount = series.filter(
        (p) => isFiniteNumber(p.speed) && p.speed > 0.5,
    ).length;

    let metric: IntervalDetectionMetric;
    let rawValues: Array<number | null>;
    let thresholdMultiplier: number;
    let minMeanMultiplier: number;

    if (powerValidCount >= minCoverageCount) {
        metric = 'power';
        rawValues = series.map((p) =>
            isFiniteNumber(p.power) && p.power >= 0 ? p.power : null,
        );
        thresholdMultiplier = 1.2;
        minMeanMultiplier = 1.16;
    } else if (!isCycling && gapValidCount >= minCoverageCount) {
        metric = 'gap_speed';
        rawValues = series.map((p) =>
            isFiniteNumber(p.gap_speed) && p.gap_speed > 0.5
                ? p.gap_speed
                : null,
        );
        thresholdMultiplier = 1.12;
        minMeanMultiplier = 1.09;
    } else if (speedValidCount >= minCoverageCount) {
        metric = 'speed';
        rawValues = series.map((p) =>
            isFiniteNumber(p.speed) && p.speed > 0.5 ? p.speed : null,
        );
        thresholdMultiplier = isCycling ? 1.15 : 1.12;
        minMeanMultiplier = isCycling ? 1.12 : 1.09;
    } else {
        return [];
    }

    const baselineSamples = rawValues.filter((v): v is number =>
        metric === 'power' ? v !== null && v > 10 : v !== null && v > 0.5,
    );
    const baselineMedian = medianOf(baselineSamples);

    if (baselineMedian === null || baselineMedian <= 0) {
        return [];
    }

    const times = series.map((p) => p.time);
    const smoothed = smoothByTimeWindow(times, rawValues, 4);
    const threshold = baselineMedian * thresholdMultiplier;

    const rawRuns: Array<{ start: number; end: number }> = [];
    let runStart: number | null = null;

    for (let i = 0; i < smoothed.length; i += 1) {
        const val = smoothed[i];
        const isHigh = val !== null && val >= threshold;

        if (isHigh && runStart === null) {
            runStart = i;
        } else if (!isHigh && runStart !== null) {
            rawRuns.push({ start: runStart, end: i - 1 });
            runStart = null;
        }
    }

    if (runStart !== null) {
        rawRuns.push({ start: runStart, end: smoothed.length - 1 });
    }

    if (rawRuns.length === 0) {
        return [];
    }

    // Merge consecutive surges separated by gaps <= 10s
    const mergedRuns: Array<{ start: number; end: number }> = [rawRuns[0]];

    for (let i = 1; i < rawRuns.length; i += 1) {
        const prev = mergedRuns[mergedRuns.length - 1];
        const curr = rawRuns[i];
        const gapSeconds = times[curr.start] - times[prev.end];

        if (gapSeconds <= 10) {
            prev.end = curr.end;
        } else {
            mergedRuns.push({ ...curr });
        }
    }

    const candidates: Array<{
        startIndex: number;
        endIndex: number;
        durationSeconds: number;
        intensityRatio: number;
        prominence: number;
        stats: StreamSelectionStats;
    }> = [];

    for (const run of mergedRuns) {
        const durationSeconds = times[run.end] - times[run.start];

        if (durationSeconds < 30 || durationSeconds > totalDuration * 0.75) {
            continue;
        }

        let sum = 0;
        let count = 0;

        for (let i = run.start; i <= run.end; i += 1) {
            const v = rawValues[i];

            if (v !== null) {
                sum += v;
                count += 1;
            }
        }

        if (count === 0) {
            continue;
        }

        const meanOutput = sum / count;
        const intensityRatio = meanOutput / baselineMedian;

        if (intensityRatio < minMeanMultiplier) {
            continue;
        }

        const stats = computeStreamSelectionStats(
            series,
            run.start,
            run.end,
            weather,
        );

        if (!stats || stats.durationSeconds < 30) {
            continue;
        }

        candidates.push({
            startIndex: run.start,
            endIndex: run.end,
            durationSeconds: stats.durationSeconds,
            intensityRatio: roundTo(intensityRatio, 3),
            prominence:
                (intensityRatio - 1) * Math.sqrt(Math.max(30, durationSeconds)),
            stats,
        });
    }

    if (candidates.length === 0) {
        return [];
    }

    const cleanest =
        candidates.length > 12
            ? [...candidates]
                  .sort((a, b) => b.prominence - a.prominence)
                  .slice(0, 12)
            : candidates;

    cleanest.sort((a, b) => a.startIndex - b.startIndex);

    return cleanest.map((item, idx) => ({
        id: `interval-${idx + 1}`,
        index: idx + 1,
        label: `Interval ${idx + 1}`,
        shortLabel: `Int ${idx + 1}`,
        metric,
        startIndex: item.startIndex,
        endIndex: item.endIndex,
        intensityRatio: item.intensityRatio,
        stats: item.stats,
    }));
}

/**
 * Find the natural midpoint index of an activity stream (by distance when available, else elapsed time).
 */
export function findMidpointIndex(series: StreamPoint[]): number {
    if (series.length <= 2) {
        return 0;
    }

    const firstDist = series.find((p) => isFiniteNumber(p.distance))?.distance;
    const lastDist = [...series]
        .reverse()
        .find((p) => isFiniteNumber(p.distance))?.distance;

    if (
        firstDist !== undefined &&
        lastDist !== undefined &&
        firstDist !== null &&
        lastDist !== null &&
        lastDist > firstDist
    ) {
        const midDist = (firstDist + lastDist) / 2;

        for (let i = 1; i < series.length - 1; i += 1) {
            const dist = series[i].distance;

            if (isFiniteNumber(dist) && dist >= midDist) {
                return i;
            }
        }
    }

    const startTime = series[0].time;
    const endTime = series[series.length - 1].time;

    if (endTime > startTime) {
        const midTime = (startTime + endTime) / 2;

        for (let i = 1; i < series.length - 1; i += 1) {
            if (series[i].time >= midTime) {
                return i;
            }
        }
    }

    return Math.floor((series.length - 1) / 2);
}
