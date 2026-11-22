const EMPTY_VALUE = '—';

const shortDateFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

export function formatNumber(
    value: number | null | undefined,
    decimals?: number,
): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return EMPTY_VALUE;
    }

    const options: Intl.NumberFormatOptions =
        decimals !== undefined
            ? {
                  minimumFractionDigits: decimals,
                  maximumFractionDigits: decimals,
              }
            : {};

    return new Intl.NumberFormat('en-US', options).format(value);
}

export function formatDistanceKm(
    meters: number | null | undefined,
    decimals = 2,
): string {
    if (meters === null || meters === undefined || !Number.isFinite(meters)) {
        return EMPTY_VALUE;
    }

    return `${formatNumber(meters / 1000, decimals)} km`;
}

export function formatDurationClock(
    seconds: number | null | undefined,
): string {
    if (
        seconds === null ||
        seconds === undefined ||
        !Number.isFinite(seconds) ||
        seconds < 0
    ) {
        return EMPTY_VALUE;
    }

    const totalSeconds = Math.round(seconds);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const remainingSeconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    }

    return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`;
}

export function formatDurationHuman(
    seconds: number | null | undefined,
): string {
    if (
        seconds === null ||
        seconds === undefined ||
        !Number.isFinite(seconds) ||
        seconds < 0
    ) {
        return EMPTY_VALUE;
    }

    const totalMinutes = Math.round(seconds / 60);

    if (totalMinutes === 0) {
        return seconds > 0 ? `${Math.round(seconds)}s` : '0m';
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours > 0 && minutes > 0) {
        return `${hours}h ${minutes}m`;
    }

    if (hours > 0) {
        return `${hours}h`;
    }

    return `${minutes}m`;
}

export function formatDuration(seconds: number | null | undefined): string {
    return formatDurationClock(seconds);
}

export function formatPace(
    speedMetersPerSec: number | null | undefined,
): string {
    if (
        speedMetersPerSec === null ||
        speedMetersPerSec === undefined ||
        !Number.isFinite(speedMetersPerSec) ||
        speedMetersPerSec <= 0
    ) {
        return EMPTY_VALUE;
    }

    const secondsPerKm = Math.round(1000 / speedMetersPerSec);
    const minutes = Math.floor(secondsPerKm / 60);
    const seconds = secondsPerKm % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')} /km`;
}

export function formatSpeed(
    speedMetersPerSec: number | null | undefined,
): string {
    if (
        speedMetersPerSec === null ||
        speedMetersPerSec === undefined ||
        !Number.isFinite(speedMetersPerSec) ||
        speedMetersPerSec < 0
    ) {
        return EMPTY_VALUE;
    }

    return `${(speedMetersPerSec * 3.6).toFixed(1)} km/h`;
}

export function formatElevation(meters: number | null | undefined): string {
    if (meters === null || meters === undefined || !Number.isFinite(meters)) {
        return EMPTY_VALUE;
    }

    return `${formatNumber(Math.round(meters), 0)} m`;
}

export function formatShortDate(dateStr: string | null | undefined): string {
    if (!dateStr) {
        return EMPTY_VALUE;
    }

    const trimmed = dateStr.trim();
    const dateOnlyMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(trimmed);

    const parsed = dateOnlyMatch
        ? new Date(
              Number(dateOnlyMatch[1]),
              Number(dateOnlyMatch[2]) - 1,
              Number(dateOnlyMatch[3]),
          )
        : new Date(trimmed);

    if (Number.isNaN(parsed.getTime())) {
        return EMPTY_VALUE;
    }

    return shortDateFormatter.format(parsed);
}

export function formatRelativeTime(iso: string | null | undefined): string {
    if (!iso) {
        return 'Never';
    }

    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return 'Never';
    }

    const diffSeconds = Math.floor((Date.now() - parsed.getTime()) / 1000);

    if (diffSeconds < 60) {
        return 'Just now';
    }

    const diffMinutes = Math.floor(diffSeconds / 60);

    if (diffMinutes < 60) {
        return `${diffMinutes}m ago`;
    }

    const diffHours = Math.floor(diffMinutes / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    if (diffDays < 30) {
        return `${diffDays}d ago`;
    }

    return formatShortDate(iso);
}

export function formatTimeOfDay(isoOrLocal: string | null | undefined): string {
    if (!isoOrLocal) {
        return EMPTY_VALUE;
    }

    const trimmed = isoOrLocal.trim();

    if (/^\d{2}:\d{2}(:\d{2})?$/.test(trimmed)) {
        return trimmed.slice(0, 5);
    }

    const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(trimmed);

    if (!hasTimezone) {
        const localDateTimeMatch = /^\d{4}-\d{2}-\d{2}[T ](\d{2}:\d{2})/.exec(
            trimmed,
        );

        if (localDateTimeMatch) {
            return localDateTimeMatch[1];
        }
    }

    const parsed = new Date(trimmed);

    if (Number.isNaN(parsed.getTime())) {
        return EMPTY_VALUE;
    }

    return `${String(parsed.getHours()).padStart(2, '0')}:${String(parsed.getMinutes()).padStart(2, '0')}`;
}
