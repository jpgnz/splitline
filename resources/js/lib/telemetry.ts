export interface StartupDottedSeriesResult {
    solidData: Array<number | null>;
    dottedData?: Array<number | null>;
    tooltipValues: Array<number | null>;
    yMin?: number;
    yMax?: number;
}

export interface PaceTelemetrySeriesResult extends StartupDottedSeriesResult {
    yMin: number;
    yMax: number;
}

export function percentileOf(sorted: number[], p: number): number {
    if (sorted.length === 0) {
        return 0;
    }

    const idx = (sorted.length - 1) * p;
    const lo = Math.floor(idx);
    const hi = Math.ceil(idx);

    if (lo === hi) {
        return sorted[lo];
    }

    return sorted[lo] + (idx - lo) * (sorted[hi] - sorted[lo]);
}

export function rollingAverage(
    values: Array<number | null>,
    windowSize: number,
): Array<number | null> {
    const half = Math.floor(windowSize / 2);
    const out: Array<number | null> = [];

    for (let i = 0; i < values.length; i += 1) {
        if (values[i] === null) {
            out.push(null);

            continue;
        }

        let sum = 0;
        let count = 0;
        const start = Math.max(0, i - half);
        const end = Math.min(values.length - 1, i + half);

        for (let j = start; j <= end; j += 1) {
            const v = values[j];

            if (v !== null && Number.isFinite(v)) {
                sum += v;
                count += 1;
            }
        }

        out.push(count > 0 ? Number((sum / count).toFixed(1)) : null);
    }

    return out;
}

function roundDecimal(value: number, decimals = 3): number {
    const factor = 10 ** decimals;

    return Math.round(value * factor) / factor;
}

function roundStepDown(value: number, step: number): number {
    return roundDecimal(Math.floor(value / step) * step, 4);
}

function roundStepUp(value: number, step: number): number {
    return roundDecimal(Math.ceil(value / step) * step, 4);
}

/**
 * Clamp out-of-range dotted startup values slightly inside [yMin, yMax] so the
 * dotted line remains cleanly visible along the chart edge without clipping or
 * hiding behind the axis border, while keeping the Y-axis bounds unbiased.
 */
function clampToChartEdge(value: number, yMin: number, yMax: number): number {
    const span = yMax - yMin;
    const inset = span > 0 ? span * 0.015 : 0;
    const lo = yMin + inset;
    const hi = yMax - inset;

    if (value < lo) {
        return roundDecimal(lo, 3);
    }

    if (value > hi) {
        return roundDecimal(hi, 3);
    }

    return value;
}

/**
 * Build pace telemetry data with unbiased steady Y-axis bounds and a connected
 * dotted startup line for excluded early ramp-up / GNSS overshoot samples.
 */
export function buildPaceTelemetrySeries(params: {
    times: number[];
    speeds: Array<number | null>;
    paceSecondsFromMps: (mps: number) => number | null;
    isImperial: boolean;
    startupWindowSeconds?: number;
    minDurationSeconds?: number;
}): PaceTelemetrySeriesResult {
    const {
        times,
        speeds,
        paceSecondsFromMps,
        isImperial,
        startupWindowSeconds = 15,
        minDurationSeconds = 90,
    } = params;

    const minPaceBound = isImperial ? 190 : 120;
    const maxPaceBound = isImperial ? 1440 : 900;
    const maxStartupPaceBound = isImperial ? 5400 : 3600;

    const lastTime = times.length > 0 ? times[times.length - 1] : 0;
    const skipStartup = lastTime > minDurationSeconds;

    const steadyRawPace: Array<number | null> = speeds.map((speed) => {
        if (speed === null || speed <= 0.6) {
            return null;
        }

        const secPerUnit = paceSecondsFromMps(speed);

        if (secPerUnit === null) {
            return null;
        }

        return secPerUnit >= minPaceBound && secPerUnit <= maxPaceBound
            ? Math.round(secPerUnit)
            : null;
    });

    // For early startup points, also retain slower moving speeds (> 0.25 m/s) so
    // walk-to-run / treadmill starts show as a dotted ramp instead of a gap.
    const startupRawPace: Array<number | null> = speeds.map((speed, idx) => {
        if (!skipStartup || times[idx] > startupWindowSeconds) {
            return steadyRawPace[idx];
        }

        if (speed === null || speed <= 0.25) {
            return null;
        }

        const secPerUnit = paceSecondsFromMps(speed);

        if (secPerUnit === null) {
            return null;
        }

        return secPerUnit >= minPaceBound && secPerUnit <= maxStartupPaceBound
            ? Math.round(secPerUnit)
            : null;
    });

    const stablePaces = steadyRawPace
        .filter((val, idx): val is number => {
            if (val === null) {
                return false;
            }

            return !skipStartup || times[idx] > startupWindowSeconds;
        })
        .sort((a, b) => a - b);

    const defaultFast = isImperial ? 385 : 240;
    const defaultSlow = isImperial ? 770 : 480;
    const p02 =
        stablePaces.length > 5
            ? percentileOf(stablePaces, 0.02)
            : (stablePaces[0] ?? defaultFast);
    const p98 =
        stablePaces.length > 5
            ? percentileOf(stablePaces, 0.98)
            : (stablePaces[stablePaces.length - 1] ?? defaultSlow);

    const yMin = Math.max(minPaceBound, Math.floor((p02 - 15) / 15) * 15);
    const yMax = Math.max(
        yMin + 15,
        Math.min(maxPaceBound, Math.ceil((p98 + 20) / 15) * 15),
    );

    let lastStartupOutlierIdx = -1;

    if (skipStartup) {
        for (let i = 0; i < times.length; i += 1) {
            if (times[i] > startupWindowSeconds) {
                break;
            }

            const val = startupRawPace[i];

            if (val !== null && (val > yMax || val < yMin)) {
                lastStartupOutlierIdx = i;
            }
        }
    }

    const cleanedSolid = steadyRawPace.map((val, idx) => {
        if (idx <= lastStartupOutlierIdx || val === null) {
            return null;
        }

        return Math.max(yMin, Math.min(yMax, val));
    });
    const smoothedSolid = rollingAverage(cleanedSolid, 3);

    if (lastStartupOutlierIdx >= 0) {
        const bridgeIdx = smoothedSolid.findIndex(
            (val, idx) => idx > lastStartupOutlierIdx && val !== null,
        );

        if (bridgeIdx !== -1) {
            const dottedData: Array<number | null> = times.map((_, idx) => {
                if (idx <= lastStartupOutlierIdx) {
                    const val = startupRawPace[idx];

                    return val !== null
                        ? clampToChartEdge(val, yMin, yMax)
                        : null;
                }

                if (idx === bridgeIdx) {
                    return smoothedSolid[bridgeIdx];
                }

                return null;
            });

            const tooltipValues: Array<number | null> = times.map((_, idx) =>
                idx <= lastStartupOutlierIdx
                    ? startupRawPace[idx]
                    : smoothedSolid[idx],
            );

            return {
                solidData: smoothedSolid,
                dottedData,
                tooltipValues,
                yMin,
                yMax,
            };
        }
    }

    const fallbackSolid = rollingAverage(
        steadyRawPace.map((val) =>
            val !== null ? Math.max(yMin, Math.min(yMax, val)) : null,
        ),
        3,
    );

    return {
        solidData: fallbackSolid,
        tooltipValues: fallbackSolid,
        yMin,
        yMax,
    };
}

/**
 * Separate early startup ramp-up / sensor-lock outliers into a connected dotted
 * startup series while deriving clean, unbiased Y-axis bounds from steady data.
 */
export function buildStartupGatedTelemetrySeries(params: {
    times: number[];
    values: Array<number | null>;
    step: number;
    minMargin: number;
    excludeZero?: boolean;
    minFloor?: number;
    startupWindowSeconds?: number;
    minDurationSeconds?: number;
}): StartupDottedSeriesResult {
    const {
        times,
        values,
        step,
        minMargin,
        excludeZero = true,
        minFloor = 0,
        startupWindowSeconds = 15,
        minDurationSeconds = 90,
    } = params;

    const lastTime = times.length > 0 ? times[times.length - 1] : 0;
    const skipStartup = lastTime > minDurationSeconds;

    const stableValues: number[] = [];

    for (let i = 0; i < values.length; i += 1) {
        const val = values[i];

        if (val === null || !Number.isFinite(val)) {
            continue;
        }

        if (excludeZero && val <= 0) {
            continue;
        }

        if (skipStartup && times[i] <= startupWindowSeconds) {
            continue;
        }

        stableValues.push(val);
    }

    if (stableValues.length <= 5) {
        return {
            solidData: values,
            tooltipValues: values,
        };
    }

    stableValues.sort((a, b) => a - b);

    const stableMin = stableValues[0];
    const stableMax = stableValues[stableValues.length - 1];
    const p02 = percentileOf(stableValues, 0.02);
    const p98 = percentileOf(stableValues, 0.98);

    const stableSpan = Math.max(stableMax - stableMin, step);
    const yMin = Math.max(
        minFloor,
        roundStepDown(stableMin - stableSpan * 0.05, step),
    );
    const rawYMax = roundStepUp(stableMax + stableSpan * 0.05, step);
    const yMax = rawYMax > yMin ? rawYMax : roundDecimal(yMin + step, 4);

    const coreSpan = Math.max(p98 - p02, minMargin);
    const coreMargin = Math.max(coreSpan * 0.25, minMargin);
    const loCore = p02 - coreMargin;
    const hiCore = p98 + coreMargin;

    let lastStartupOutlierIdx = -1;

    if (skipStartup) {
        for (let i = 0; i < values.length; i += 1) {
            if (times[i] > startupWindowSeconds) {
                break;
            }

            const val = values[i];

            if (val === null || !Number.isFinite(val)) {
                continue;
            }

            const isOutlier =
                (excludeZero && val <= 0) ||
                val < yMin ||
                val > yMax ||
                val < loCore ||
                val > hiCore;

            if (isOutlier) {
                lastStartupOutlierIdx = i;
            }
        }
    }

    const solidData: Array<number | null> = values.map((val, idx) => {
        if (idx <= lastStartupOutlierIdx) {
            return null;
        }

        if (val === null || !Number.isFinite(val)) {
            return null;
        }

        if (excludeZero && val <= 0) {
            return null;
        }

        return val;
    });

    const tooltipValues: Array<number | null> = values.map((val) =>
        val !== null && Number.isFinite(val) ? val : null,
    );

    if (lastStartupOutlierIdx >= 0) {
        const bridgeIdx = solidData.findIndex(
            (val, idx) => idx > lastStartupOutlierIdx && val !== null,
        );

        if (bridgeIdx !== -1) {
            const dottedData: Array<number | null> = values.map((val, idx) => {
                if (idx <= lastStartupOutlierIdx) {
                    if (val === null || !Number.isFinite(val)) {
                        return null;
                    }

                    return clampToChartEdge(val, yMin, yMax);
                }

                if (idx === bridgeIdx) {
                    return solidData[bridgeIdx];
                }

                return null;
            });

            return {
                solidData,
                dottedData,
                tooltipValues,
                yMin,
                yMax,
            };
        }

        return {
            solidData: values,
            tooltipValues,
        };
    }

    return {
        solidData,
        tooltipValues,
        yMin,
        yMax,
    };
}
