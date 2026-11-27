import { computeStreamSelectionStats } from '@/lib/streamSelection';
import type { StreamPoint } from '@/types/activity';

export type BestEffortMode = 'distance' | 'power' | 'gap' | 'hr' | 'climbing';

export type DurationPeakMode = Exclude<BestEffortMode, 'distance'>;

export interface DurationPeakEffort {
    id: string;
    mode: DurationPeakMode;
    durationSeconds: number;
    label: string;
    startTime: number;
    endTime: number;
    startIndex: number;
    endIndex: number;
    startDistanceMeters: number | null;
    endDistanceMeters: number | null;
    value: number;
    avgPower: number | null;
    wPerKg: number | null;
    avgHr: number | null;
    avgSpeed: number | null;
    avgGapSpeed: number | null;
    elevationGain: number | null;
    elevationDelta: number | null;
    avgGradePct: number | null;
    vam: number | null;
    allTimeRank: number | null;
}

export interface PeakEffortOptions {
    isRunning: boolean;
    isCycling: boolean;
    isIndoor: boolean;
    athleteWeightKg?: number | null;
    powerEffortRanks?: Record<string, number> | null;
    powerCurve?: Record<string, number> | null;
}

export interface AllDurationPeakEfforts {
    power: DurationPeakEffort[];
    gap: DurationPeakEffort[];
    hr: DurationPeakEffort[];
    climbing: DurationPeakEffort[];
}

const PAUSE_MIN_SECONDS = 15;
const PAUSE_MEDIAN_MULTIPLIER = 4;
const ALTITUDE_SMOOTHING_RADIUS = 2;
const ALTITUDE_NOISE_THRESHOLD_METERS = 0.2;
const MIN_WINDOW_COVERAGE = 0.85;

/**
 * Stored streams are downsampled, so a window must span at least this many sample
 * intervals before its stream-derived average is meaningful.
 */
const MIN_WINDOW_SAMPLE_INTERVALS = 3;

const RUNNING_POWER_DURATIONS: readonly number[] = [
    5, 10, 30, 60, 120, 300, 600, 1200, 1800, 3600,
];

const CYCLING_POWER_DURATIONS: readonly number[] = [
    5, 10, 30, 60, 120, 300, 600, 1200, 1800, 3600, 7200,
];

const SUSTAINED_DURATIONS: readonly number[] = [60, 300, 600, 1200, 1800, 3600];

const DURATION_LABELS: Record<number, string> = {
    5: '5s',
    10: '10s',
    30: '30s',
    60: '1 min',
    120: '2 min',
    300: '5 min',
    600: '10 min',
    1200: '20 min',
    1800: '30 min',
    3600: '1 hr',
    7200: '2 hr',
};

function formatPeakDurationLabel(seconds: number): string {
    if (DURATION_LABELS[seconds]) {
        return DURATION_LABELS[seconds];
    }

    if (seconds >= 3600) {
        return `${seconds / 3600} hr`;
    }

    if (seconds >= 60) {
        return `${Math.round(seconds / 60)} min`;
    }

    return `${seconds}s`;
}

function isFiniteNumber(value: number | null | undefined): value is number {
    return typeof value === 'number' && Number.isFinite(value);
}

interface IndexedSample {
    origIndex: number;
    time: number;
    distance: number | null;
    point: StreamPoint;
}

function normalizeMonotonicSamples(series: StreamPoint[]): IndexedSample[] {
    const samples: IndexedSample[] = [];
    let lastTime = -Infinity;

    for (let i = 0; i < series.length; i += 1) {
        const pt = series[i];

        if (!isFiniteNumber(pt.time) || pt.time < lastTime) {
            continue;
        }

        if (samples.length > 0 && pt.time === lastTime) {
            continue;
        }

        lastTime = pt.time;
        samples.push({
            origIndex: i,
            time: pt.time,
            distance: isFiniteNumber(pt.distance) ? pt.distance : null,
            point: pt,
        });
    }

    return samples;
}

function resolveMedianSampleSpacing(samples: IndexedSample[]): number | null {
    const deltas: number[] = [];

    for (let i = 1; i < samples.length; i += 1) {
        const dt = samples[i].time - samples[i - 1].time;

        if (dt > 0) {
            deltas.push(dt);
        }
    }

    if (deltas.length === 0) {
        return null;
    }

    deltas.sort((a, b) => a - b);

    return deltas[Math.floor(deltas.length / 2)];
}

function resolvePauseThreshold(medianSpacing: number | null): number {
    if (medianSpacing === null) {
        return PAUSE_MIN_SECONDS;
    }

    return Math.max(PAUSE_MIN_SECONDS, medianSpacing * PAUSE_MEDIAN_MULTIPLIER);
}

function resolveMinResolvableDuration(medianSpacing: number | null): number {
    return (medianSpacing ?? 0) * MIN_WINDOW_SAMPLE_INTERVALS;
}

/**
 * Device-recorded peak power keyed by whole-second duration, ignoring non-positive or malformed entries.
 */
function normalizePowerCurve(
    curve: Record<string, number> | null | undefined,
): Map<number, number> {
    const normalized = new Map<number, number>();

    if (!curve || Array.isArray(curve)) {
        return normalized;
    }

    for (const [rawSeconds, rawWatts] of Object.entries(curve)) {
        const seconds = Number(rawSeconds);
        const watts = Number(rawWatts);

        if (
            Number.isInteger(seconds) &&
            seconds > 0 &&
            Number.isFinite(watts) &&
            watts > 0
        ) {
            normalized.set(seconds, watts);
        }
    }

    return normalized;
}

function interpolateAtTime(
    times: Float64Array,
    values: Float64Array,
    idx: number,
    targetTime: number,
): number {
    const n = times.length;

    if (idx <= 0 && targetTime <= times[0]) {
        return values[0];
    }

    if (idx >= n - 1) {
        return values[n - 1];
    }

    const t0 = times[idx];
    const t1 = times[idx + 1];
    const span = t1 - t0;

    if (span <= 0) {
        return values[idx];
    }

    const ratio = Math.max(0, Math.min(1, (targetTime - t0) / span));

    return values[idx] + (values[idx + 1] - values[idx]) * ratio;
}

function interpolateDistanceAtTime(
    samples: IndexedSample[],
    hintIndex: number,
    targetTime: number,
): number | null {
    const n = samples.length;

    if (n === 0) {
        return null;
    }

    const clamped = Math.max(0, Math.min(n - 1, hintIndex));
    const left = samples[clamped];

    if (clamped + 1 < n) {
        const right = samples[clamped + 1];

        if (
            left.distance !== null &&
            right.distance !== null &&
            targetTime >= left.time &&
            targetTime <= right.time
        ) {
            const span = right.time - left.time;
            const ratio = span > 0 ? (targetTime - left.time) / span : 0;

            return left.distance + (right.distance - left.distance) * ratio;
        }
    }

    if (left.distance !== null) {
        return left.distance;
    }

    for (let offset = 1; offset <= 5; offset += 1) {
        if (
            clamped - offset >= 0 &&
            samples[clamped - offset].distance !== null
        ) {
            return samples[clamped - offset].distance;
        }

        if (
            clamped + offset < n &&
            samples[clamped + offset].distance !== null
        ) {
            return samples[clamped + offset].distance;
        }
    }

    return null;
}

interface BestWindowMatch {
    score: number;
    startTime: number;
    endTime: number;
    startSampleIdx: number;
    endSampleIdx: number;
}

/**
 * O(N) two-pointer sliding window maximizing a cumulative metric over a fixed duration.
 *
 * When `divideByDuration` is true, score = deltaMetric / duration (elapsed average; pauses dilute).
 * When `divideByDuration` is false and `divideByValidTime` is true, score = deltaMetric / deltaValid.
 * When both are false, score = deltaMetric (e.g., total elevation gain in the window).
 */
function findBestDurationWindow(
    times: Float64Array,
    prefixMetric: Float64Array,
    prefixValid: Float64Array,
    durationSeconds: number,
    mode: 'elapsed_avg' | 'valid_avg' | 'total',
    minScore = 0,
): BestWindowMatch | null {
    const n = times.length;

    if (n < 2 || times[n - 1] - times[0] < durationSeconds) {
        return null;
    }

    const minValid = durationSeconds * MIN_WINDOW_COVERAGE;
    let best: BestWindowMatch | null = null;

    const consider = (
        startTime: number,
        endTime: number,
        startIdx: number,
        endIdx: number,
        startMetric: number,
        endMetric: number,
        startValid: number,
        endValid: number,
    ): void => {
        const deltaValid = endValid - startValid;

        if (deltaValid < minValid) {
            return;
        }

        const deltaMetric = endMetric - startMetric;
        let score: number;

        if (mode === 'elapsed_avg') {
            score = deltaMetric / durationSeconds;
        } else if (mode === 'valid_avg') {
            score = deltaValid > 0 ? deltaMetric / deltaValid : 0;
        } else {
            score = deltaMetric;
        }

        if (!Number.isFinite(score) || score <= minScore) {
            return;
        }

        if (best === null || score > best.score) {
            best = {
                score,
                startTime,
                endTime,
                startSampleIdx: startIdx,
                endSampleIdx: endIdx,
            };
        }
    };

    // Sweep 1: windows ending at each sample time t[r]
    let left = 0;

    for (let r = 1; r < n; r += 1) {
        const targetStart = times[r] - durationSeconds;

        if (targetStart < times[0]) {
            continue;
        }

        while (left + 1 < r && times[left + 1] <= targetStart) {
            left += 1;
        }

        const startMetric = interpolateAtTime(
            times,
            prefixMetric,
            left,
            targetStart,
        );
        const startValid = interpolateAtTime(
            times,
            prefixValid,
            left,
            targetStart,
        );

        consider(
            targetStart,
            times[r],
            left,
            r,
            startMetric,
            prefixMetric[r],
            startValid,
            prefixValid[r],
        );
    }

    // Sweep 2: windows starting at each sample time t[l]
    let right = 0;

    for (let l = 0; l < n - 1; l += 1) {
        const targetEnd = times[l] + durationSeconds;

        if (targetEnd > times[n - 1]) {
            break;
        }

        while (right + 1 < n - 1 && times[right + 1] < targetEnd) {
            right += 1;
        }

        const endMetric = interpolateAtTime(
            times,
            prefixMetric,
            right,
            targetEnd,
        );
        const endValid = interpolateAtTime(
            times,
            prefixValid,
            right,
            targetEnd,
        );
        const endIdx = Math.min(n - 1, right + 1);

        consider(
            times[l],
            targetEnd,
            l,
            endIdx,
            prefixMetric[l],
            endMetric,
            prefixValid[l],
            endValid,
        );
    }

    return best;
}

function buildMetricPrefixes(
    samples: IndexedSample[],
    pauseThreshold: number,
    extract: (point: StreamPoint) => number | null,
): {
    times: Float64Array;
    prefixArea: Float64Array;
    prefixValid: Float64Array;
    validCount: number;
} {
    const n = samples.length;
    const times = new Float64Array(n);
    const prefixArea = new Float64Array(n);
    const prefixValid = new Float64Array(n);

    let prevVal: number | null = null;
    let validCount = 0;

    for (let i = 0; i < n; i += 1) {
        const t = samples[i].time;
        const val = extract(samples[i].point);

        times[i] = t;

        if (val !== null) {
            validCount += 1;
        }

        if (i === 0) {
            prevVal = val;

            continue;
        }

        const dt = t - times[i - 1];
        let areaDelta = 0;
        let validDelta = 0;

        if (dt > 0 && dt <= pauseThreshold) {
            if (prevVal !== null && val !== null) {
                areaDelta = ((prevVal + val) / 2) * dt;
                validDelta = dt;
            } else if (dt <= 3 && (prevVal !== null || val !== null)) {
                const known = prevVal ?? val ?? 0;
                areaDelta = known * dt;
                validDelta = dt;
            }
        }

        prefixArea[i] = prefixArea[i - 1] + areaDelta;
        prefixValid[i] = prefixValid[i - 1] + validDelta;
        prevVal = val;
    }

    return { times, prefixArea, prefixValid, validCount };
}

function buildClimbingPrefixes(
    samples: IndexedSample[],
    pauseThreshold: number,
): {
    times: Float64Array;
    prefixGain: Float64Array;
    prefixValid: Float64Array;
    totalGain: number;
} {
    const n = samples.length;
    const times = new Float64Array(n);
    const rawAlts: Array<number | null> = Array.from(
        { length: n },
        (): number | null => null,
    );

    for (let i = 0; i < n; i += 1) {
        times[i] = samples[i].time;
        const alt = samples[i].point.altitude;
        rawAlts[i] = isFiniteNumber(alt) ? alt : null;
    }

    // Centered moving average matching ClimbDetector & streamSelection
    const smoothedAlts: Array<number | null> = Array.from(
        { length: n },
        (): number | null => null,
    );

    for (let i = 0; i < n; i += 1) {
        if (rawAlts[i] === null) {
            smoothedAlts[i] = null;

            continue;
        }

        const lo = Math.max(0, i - ALTITUDE_SMOOTHING_RADIUS);
        const hi = Math.min(n - 1, i + ALTITUDE_SMOOTHING_RADIUS);
        let sum = 0;
        let count = 0;

        for (let k = lo; k <= hi; k += 1) {
            const v = rawAlts[k];

            if (v !== null) {
                sum += v;
                count += 1;
            }
        }

        smoothedAlts[i] = count > 0 ? sum / count : null;
    }

    const prefixGain = new Float64Array(n);
    const prefixValid = new Float64Array(n);
    let totalGain = 0;

    for (let i = 1; i < n; i += 1) {
        const dt = times[i] - times[i - 1];
        const prev = smoothedAlts[i - 1];
        const curr = smoothedAlts[i];
        let gainDelta = 0;
        let validDelta = 0;

        if (dt > 0 && dt <= pauseThreshold && prev !== null && curr !== null) {
            validDelta = dt;
            const delta = curr - prev;

            if (delta > ALTITUDE_NOISE_THRESHOLD_METERS) {
                gainDelta = delta;
                totalGain += delta;
            }
        }

        prefixGain[i] = prefixGain[i - 1] + gainDelta;
        prefixValid[i] = prefixValid[i - 1] + validDelta;
    }

    return { times, prefixGain, prefixValid, totalGain };
}

function resolvePowerRank(
    ranks: Record<string, number> | null | undefined,
    durationSeconds: number,
): number | null {
    if (!ranks || Array.isArray(ranks)) {
        return null;
    }

    const value = ranks[String(durationSeconds)];

    return typeof value === 'number' && value >= 1 && value <= 3 ? value : null;
}

function buildPeakEffortRow(
    series: StreamPoint[],
    samples: IndexedSample[],
    mode: DurationPeakMode,
    durationSeconds: number,
    match: BestWindowMatch,
    athleteWeightKg: number | null,
    allTimeRank: number | null,
    recordedValue: number | null = null,
): DurationPeakEffort {
    const startOrig = samples[match.startSampleIdx].origIndex;
    const endOrig = samples[match.endSampleIdx].origIndex;
    const stats = computeStreamSelectionStats(series, startOrig, endOrig);
    const value = recordedValue ?? match.score;

    const startDist = interpolateDistanceAtTime(
        samples,
        match.startSampleIdx,
        match.startTime,
    );
    const endDist = interpolateDistanceAtTime(
        samples,
        match.endSampleIdx,
        match.endTime,
    );

    const resolvedStartDist =
        startDist !== null && endDist !== null && endDist > startDist
            ? startDist
            : (stats?.startDistanceMeters ?? null);
    const resolvedEndDist =
        startDist !== null && endDist !== null && endDist > startDist
            ? endDist
            : (stats?.endDistanceMeters ?? null);

    const avgPower =
        mode === 'power' ? Math.round(value) : (stats?.avgPower ?? null);
    const wPerKg =
        avgPower !== null &&
        athleteWeightKg !== null &&
        Number.isFinite(athleteWeightKg) &&
        athleteWeightKg > 0
            ? Math.round((avgPower / athleteWeightKg) * 100) / 100
            : null;

    const avgHr =
        mode === 'hr' ? Math.round(match.score) : (stats?.avgHr ?? null);
    const avgGapSpeed =
        mode === 'gap' ? match.score : (stats?.avgGapSpeed ?? null);
    const elevationGain =
        mode === 'climbing'
            ? Math.round(match.score * 10) / 10
            : (stats?.elevationGain ?? null);
    const vam =
        mode === 'climbing'
            ? Math.round((match.score / durationSeconds) * 3600)
            : (stats?.vam ?? null);

    return {
        id: `${mode}-${durationSeconds}`,
        mode,
        durationSeconds,
        label: formatPeakDurationLabel(durationSeconds),
        startTime: match.startTime,
        endTime: match.endTime,
        startIndex: startOrig,
        endIndex: endOrig,
        startDistanceMeters: resolvedStartDist,
        endDistanceMeters: resolvedEndDist,
        value,
        avgPower,
        wPerKg,
        avgHr,
        avgSpeed: stats?.avgSpeed ?? null,
        avgGapSpeed,
        elevationGain,
        elevationDelta: stats?.netElevation ?? null,
        avgGradePct: stats?.avgGradePct ?? null,
        vam,
        allTimeRank,
    };
}

/**
 * Peak power per duration. Values come from the device-recorded power curve when available, because the
 * stored stream is downsampled and under-reads short efforts; the stream is then only used to place each
 * window on the charts. Without a power curve, only durations the stream can resolve are reported.
 */
export function computePowerPeakEfforts(
    series: StreamPoint[],
    options: {
        isCycling: boolean;
        athleteWeightKg?: number | null;
        powerEffortRanks?: Record<string, number> | null;
        powerCurve?: Record<string, number> | null;
    },
): DurationPeakEffort[] {
    const samples = normalizeMonotonicSamples(series);

    if (samples.length < 5) {
        return [];
    }

    const hasPositivePower = samples.some(
        (s) => isFiniteNumber(s.point.power) && s.point.power > 0,
    );

    if (!hasPositivePower) {
        return [];
    }

    const medianSpacing = resolveMedianSampleSpacing(samples);
    const { times, prefixArea, prefixValid, validCount } = buildMetricPrefixes(
        samples,
        resolvePauseThreshold(medianSpacing),
        (pt) => (isFiniteNumber(pt.power) && pt.power >= 0 ? pt.power : null),
    );

    if (validCount < 5) {
        return [];
    }

    const durations = options.isCycling
        ? CYCLING_POWER_DURATIONS
        : RUNNING_POWER_DURATIONS;
    const powerCurve = normalizePowerCurve(options.powerCurve);
    const hasPowerCurve = powerCurve.size > 0;
    const minResolvableDuration = resolveMinResolvableDuration(medianSpacing);
    const weightKg = options.athleteWeightKg ?? null;
    const results: DurationPeakEffort[] = [];

    for (const duration of durations) {
        const recordedWatts = powerCurve.get(duration) ?? null;

        if (
            hasPowerCurve
                ? recordedWatts === null
                : duration < minResolvableDuration
        ) {
            continue;
        }

        const match = findBestDurationWindow(
            times,
            prefixArea,
            prefixValid,
            duration,
            'elapsed_avg',
            0,
        );

        if (!match) {
            continue;
        }

        results.push(
            buildPeakEffortRow(
                series,
                samples,
                'power',
                duration,
                match,
                weightKg,
                recordedWatts !== null
                    ? resolvePowerRank(options.powerEffortRanks, duration)
                    : null,
                recordedWatts,
            ),
        );
    }

    return results;
}

export function computeGapPeakEfforts(
    series: StreamPoint[],
    isRunning: boolean,
): DurationPeakEffort[] {
    if (!isRunning) {
        return [];
    }

    const samples = normalizeMonotonicSamples(series);

    if (samples.length < 10) {
        return [];
    }

    const medianSpacing = resolveMedianSampleSpacing(samples);
    const { times, prefixArea, prefixValid, validCount } = buildMetricPrefixes(
        samples,
        resolvePauseThreshold(medianSpacing),
        (pt) =>
            isFiniteNumber(pt.gap_speed) &&
            pt.gap_speed > 0.5 &&
            pt.gap_speed <= 12
                ? pt.gap_speed
                : null,
    );

    if (validCount < 10) {
        return [];
    }

    const minResolvableDuration = resolveMinResolvableDuration(medianSpacing);
    const results: DurationPeakEffort[] = [];

    for (const duration of SUSTAINED_DURATIONS) {
        if (duration < minResolvableDuration) {
            continue;
        }

        const match = findBestDurationWindow(
            times,
            prefixArea,
            prefixValid,
            duration,
            'elapsed_avg',
            0.5,
        );

        if (!match) {
            continue;
        }

        results.push(
            buildPeakEffortRow(
                series,
                samples,
                'gap',
                duration,
                match,
                null,
                null,
            ),
        );
    }

    return results;
}

export function computeHrPeakEfforts(
    series: StreamPoint[],
    athleteWeightKg: number | null = null,
): DurationPeakEffort[] {
    const samples = normalizeMonotonicSamples(series);

    if (samples.length < 10) {
        return [];
    }

    const medianSpacing = resolveMedianSampleSpacing(samples);
    const { times, prefixArea, prefixValid, validCount } = buildMetricPrefixes(
        samples,
        resolvePauseThreshold(medianSpacing),
        (pt) =>
            isFiniteNumber(pt.hr) && pt.hr >= 40 && pt.hr <= 240 ? pt.hr : null,
    );

    if (validCount < 10) {
        return [];
    }

    const minResolvableDuration = resolveMinResolvableDuration(medianSpacing);
    const results: DurationPeakEffort[] = [];

    for (const duration of SUSTAINED_DURATIONS) {
        if (duration < minResolvableDuration) {
            continue;
        }

        const match = findBestDurationWindow(
            times,
            prefixArea,
            prefixValid,
            duration,
            'valid_avg',
            40,
        );

        if (!match) {
            continue;
        }

        results.push(
            buildPeakEffortRow(
                series,
                samples,
                'hr',
                duration,
                match,
                athleteWeightKg,
                null,
            ),
        );
    }

    return results;
}

export function computeClimbingPeakEfforts(
    series: StreamPoint[],
    options: {
        isIndoor: boolean;
        athleteWeightKg?: number | null;
    },
): DurationPeakEffort[] {
    if (options.isIndoor) {
        return [];
    }

    const samples = normalizeMonotonicSamples(series);

    if (samples.length < 10) {
        return [];
    }

    const medianSpacing = resolveMedianSampleSpacing(samples);
    const { times, prefixGain, prefixValid, totalGain } = buildClimbingPrefixes(
        samples,
        resolvePauseThreshold(medianSpacing),
    );

    if (totalGain < 15) {
        return [];
    }

    const minResolvableDuration = resolveMinResolvableDuration(medianSpacing);
    const weightKg = options.athleteWeightKg ?? null;
    const results: DurationPeakEffort[] = [];

    for (const duration of SUSTAINED_DURATIONS) {
        if (duration < minResolvableDuration) {
            continue;
        }

        const minGainMeters = duration <= 60 ? 5 : 10;
        const match = findBestDurationWindow(
            times,
            prefixGain,
            prefixValid,
            duration,
            'total',
            minGainMeters,
        );

        if (!match) {
            continue;
        }

        results.push(
            buildPeakEffortRow(
                series,
                samples,
                'climbing',
                duration,
                match,
                weightKg,
                null,
            ),
        );
    }

    return results;
}

export function computeAllDurationPeakEfforts(
    series: StreamPoint[],
    options: PeakEffortOptions,
): AllDurationPeakEfforts {
    return {
        power: computePowerPeakEfforts(series, {
            isCycling: options.isCycling,
            athleteWeightKg: options.athleteWeightKg,
            powerEffortRanks: options.powerEffortRanks,
            powerCurve: options.powerCurve,
        }),
        gap: computeGapPeakEfforts(series, options.isRunning),
        hr: computeHrPeakEfforts(series, options.athleteWeightKg ?? null),
        climbing: computeClimbingPeakEfforts(series, {
            isIndoor: options.isIndoor,
            athleteWeightKg: options.athleteWeightKg,
        }),
    };
}

export function hasAnyDurationPeakEfforts(
    efforts: AllDurationPeakEfforts,
): boolean {
    return (
        efforts.power.length > 0 ||
        efforts.gap.length > 0 ||
        efforts.hr.length > 0 ||
        efforts.climbing.length > 0
    );
}
