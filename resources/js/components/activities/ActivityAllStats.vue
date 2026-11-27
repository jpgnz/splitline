<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatDurationClock, formatNumber } from '@/lib/format';
import { useUnits } from '@/lib/units';
import { formatCompass } from '@/lib/wind';
import type { ActivityDetail, ActivityWeatherPayload } from '@/types/activity';

const props = withDefaults(
    defineProps<{
        activity: ActivityDetail;
        usesPace: boolean;
        weather?: ActivityWeatherPayload | null;
    }>(),
    {
        weather: null,
    },
);

const {
    formatDistance,
    formatElevation,
    formatFluidVolume,
    formatPace,
    formatPrecip,
    formatSpeed,
    formatSpeedKmh,
    formatStride,
    formatTemp,
} = useUnits();

const hrUnit = ref<'bpm' | 'pct'>('bpm');
const paceUnit = ref<'pace' | 'speed'>(props.usesPace ? 'pace' : 'speed');

interface StatRow {
    label: string;
    value: string;
    sub?: string | null;
}

interface StatGroup {
    key: string;
    title: string;
    toggle?: 'hr' | 'pace';
    layout?: 'default' | 'wide' | 'full';
    rows: StatRow[];
}

function formatTeRating(score: number | null): string | null {
    if (score === null || !Number.isFinite(score)) {
        return null;
    }

    if (score >= 5.0) {
        return 'Overreaching';
    }

    if (score >= 4.0) {
        return 'Highly Improving';
    }

    if (score >= 3.0) {
        return 'Impacting';
    }

    if (score >= 2.0) {
        return 'Maintaining';
    }

    if (score >= 1.0) {
        return 'Minor';
    }

    return 'No Benefit';
}

function formatTeLabel(label: string | null): string | null {
    if (!label) {
        return null;
    }

    const mapped: Record<string, string> = {
        AEROBIC_BASE: 'Base (Low Aerobic)',
        TEMPO: 'Tempo (High Aerobic)',
        LACTATE_THRESHOLD: 'Threshold (High Aerobic)',
        VO2MAX: 'VO2 Max (High Aerobic)',
        ANAEROBIC_CAPACITY: 'Anaerobic Capacity',
        SPRINT: 'Sprint (Anaerobic)',
        RECOVERY: 'Recovery',
    };

    if (mapped[label]) {
        return mapped[label];
    }

    return label
        .toLowerCase()
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}

function formatFeel(feel: number | null): string | null {
    if (feel === null || !Number.isFinite(feel)) {
        return null;
    }

    const labels: Record<number, string> = {
        0: 'Very Weak',
        25: 'Weak',
        50: 'Normal',
        75: 'Strong',
        100: 'Very Strong',
    };

    return labels[feel] ?? `${feel}`;
}

function formatRpe(rpe: number | null): string | null {
    if (rpe === null || !Number.isFinite(rpe)) {
        return null;
    }

    const n = rpe > 10 ? rpe / 10 : rpe;
    const qualifier =
        n >= 9
            ? 'Maximum'
            : n >= 8
              ? 'Very Hard'
              : n >= 6
                ? 'Hard'
                : n >= 4
                  ? 'Moderate'
                  : n >= 2
                    ? 'Light'
                    : 'Very Light';

    return `${formatNumber(n, n % 1 === 0 ? 0 : 1)}/10 ${qualifier}`;
}

function formatExecRating(score: number): string {
    if (score >= 85) {
        return 'Good';
    }

    if (score >= 65) {
        return 'Fair';
    }

    return 'Low';
}

function formatHrValue(bpm: number | null): string {
    if (bpm === null) {
        return '—';
    }

    if (hrUnit.value === 'pct' && props.activity.hr_max_configured) {
        return `${Math.round((bpm / props.activity.hr_max_configured) * 100)}%`;
    }

    return `${bpm} bpm`;
}

function formatSpeedOrPace(mps: number | null): string {
    if (mps === null || mps <= 0) {
        return '—';
    }

    return paceUnit.value === 'pace' ? formatPace(mps) : formatSpeed(mps);
}

function formatSignedWindKmh(kmh: number): string {
    const normalized = Math.abs(kmh) < 0.05 ? 0 : kmh;
    const formatted = formatSpeedKmh(normalized, 1);

    return normalized > 0 ? `+${formatted}` : formatted;
}

function formatWork(value: number | null): string {
    if (value === null || value <= 0) {
        return '—';
    }

    const kj = value >= 10000 ? value / 1000 : value;

    return `${formatNumber(Math.round(kj), 0)} kJ`;
}

const statGroups = computed<StatGroup[]>(() => {
    const a = props.activity;
    const groups: StatGroup[] = [];

    // 1. Timing
    const timingRows: StatRow[] = [
        { label: 'Distance', value: formatDistance(a.distance_meters, 2) },
        { label: 'Time', value: formatDurationClock(a.duration_seconds) },
    ];
    if (a.moving_duration_seconds !== null) {
        timingRows.push({
            label: 'Moving Time',
            value: formatDurationClock(a.moving_duration_seconds),
        });
    }
    if (a.elapsed_duration_seconds !== null) {
        timingRows.push({
            label: 'Elapsed Time',
            value: formatDurationClock(a.elapsed_duration_seconds),
        });
    }
    groups.push({
        key: 'timing',
        title: 'Distance & Timing',
        rows: timingRows,
    });

    // 2. Pace / Speed
    const paceRows: StatRow[] = [];
    if (a.avg_speed !== null && a.avg_speed > 0) {
        paceRows.push({
            label: paceUnit.value === 'pace' ? 'Avg Pace' : 'Avg Speed',
            value: formatSpeedOrPace(a.avg_speed),
        });
    }
    if (
        a.moving_duration_seconds !== null &&
        a.moving_duration_seconds > 0 &&
        a.distance_meters > 0
    ) {
        paceRows.push({
            label:
                paceUnit.value === 'pace'
                    ? 'Avg Moving Pace'
                    : 'Avg Moving Speed',
            value: formatSpeedOrPace(
                a.distance_meters / a.moving_duration_seconds,
            ),
        });
    }
    if (a.max_speed !== null && a.max_speed > 0) {
        paceRows.push({
            label: paceUnit.value === 'pace' ? 'Best Pace' : 'Max Speed',
            value: formatSpeedOrPace(a.max_speed),
        });
    }
    if (a.avg_grade_adjusted_speed !== null && a.avg_grade_adjusted_speed > 0) {
        paceRows.push({
            label:
                paceUnit.value === 'pace'
                    ? 'Avg Grade-Adjusted Pace'
                    : 'Avg Grade-Adjusted Speed',
            value: formatSpeedOrPace(a.avg_grade_adjusted_speed),
        });
    }
    if (paceRows.length > 0) {
        groups.push({
            key: 'pace',
            title: 'Pace / Speed',
            toggle: 'pace',
            rows: paceRows,
        });
    }

    // 3. Stamina
    if (
        a.begin_stamina !== null ||
        a.end_stamina !== null ||
        a.min_stamina !== null
    ) {
        const rows: StatRow[] = [];
        if (a.begin_stamina !== null) {
            rows.push({
                label: 'Beginning Potential',
                value: `${a.begin_stamina}%`,
            });
        }
        if (a.end_stamina !== null) {
            rows.push({
                label: 'Ending Potential',
                value: `${a.end_stamina}%`,
            });
        }
        if (a.min_stamina !== null) {
            rows.push({
                label: 'Min Stamina',
                value: `${a.min_stamina}%`,
            });
        }
        if (a.begin_stamina !== null && a.end_stamina !== null) {
            rows.push({
                label: 'Stamina Drained',
                value: `-${Math.max(0, a.begin_stamina - a.end_stamina)}%`,
            });
        }
        groups.push({ key: 'stamina', title: 'Stamina', rows });
    }

    // 4. Training Effect & Intensity Minutes
    const teRows: StatRow[] = [];
    if (a.training_effect_label) {
        teRows.push({
            label: 'Primary Benefit',
            value: formatTeLabel(a.training_effect_label) ?? '—',
        });
    }
    if (a.aerobic_te !== null) {
        teRows.push({
            label: 'Aerobic',
            value: `${a.aerobic_te.toFixed(1)} ${formatTeRating(a.aerobic_te) ?? ''}`.trim(),
        });
    }
    if (a.anaerobic_te !== null) {
        teRows.push({
            label: 'Anaerobic',
            value: `${a.anaerobic_te.toFixed(1)} ${formatTeRating(a.anaerobic_te) ?? ''}`.trim(),
        });
    }
    if (a.training_load !== null) {
        teRows.push({
            label: 'Exercise Load',
            value: formatNumber(a.training_load, 0),
        });
    }
    if (
        a.moderate_intensity_minutes !== null ||
        a.vigorous_intensity_minutes !== null
    ) {
        const mod = a.moderate_intensity_minutes ?? 0;
        const vig = a.vigorous_intensity_minutes ?? 0;
        teRows.push({
            label: 'Intensity Minutes',
            value: `${mod + vig * 2} min`,
            sub: `${mod}m mod · ${vig}m vig (×2)`,
        });
    }
    if (teRows.length > 0) {
        groups.push({ key: 'te', title: 'Training Effect', rows: teRows });
    }

    // 5. Heart Rate
    if (a.avg_hr !== null || a.max_hr !== null) {
        const rows: StatRow[] = [];
        if (a.avg_hr !== null) {
            rows.push({ label: 'Avg HR', value: formatHrValue(a.avg_hr) });
        }
        if (a.max_hr !== null) {
            rows.push({ label: 'Max HR', value: formatHrValue(a.max_hr) });
        }
        if (a.hr_max_configured !== null) {
            rows.push({
                label: 'Configured Max HR',
                value: `${a.hr_max_configured} bpm`,
            });
        }
        groups.push({
            key: 'hr',
            title: 'Heart Rate',
            toggle: a.hr_max_configured ? 'hr' : undefined,
            rows,
        });
    }

    // 6. Running Dynamics
    const dynRows: StatRow[] = [];
    if (a.avg_cadence !== null) {
        dynRows.push({
            label:
                a.sport_category === 'running'
                    ? 'Avg Run Cadence'
                    : 'Avg Cadence',
            value: `${Math.round(a.avg_cadence)} ${a.sport_category === 'running' ? 'spm' : 'rpm'}`,
        });
    }
    if (a.max_cadence !== null) {
        dynRows.push({
            label:
                a.sport_category === 'running'
                    ? 'Max Run Cadence'
                    : 'Max Cadence',
            value: `${Math.round(a.max_cadence)} ${a.sport_category === 'running' ? 'spm' : 'rpm'}`,
        });
    }
    if (a.avg_stride_length !== null && a.avg_stride_length > 0) {
        dynRows.push({
            label: 'Avg Stride Length',
            value: formatStride(a.avg_stride_length),
        });
    }
    if (a.avg_vertical_ratio !== null) {
        dynRows.push({
            label: 'Avg Vertical Ratio',
            value: `${a.avg_vertical_ratio.toFixed(1)} %`,
        });
    }
    if (a.avg_vertical_oscillation !== null) {
        dynRows.push({
            label: 'Avg Vertical Oscillation',
            value: `${a.avg_vertical_oscillation.toFixed(1)} cm`,
        });
    }
    if (a.avg_ground_contact_time !== null) {
        dynRows.push({
            label: 'Avg Ground Contact Time',
            value: `${Math.round(a.avg_ground_contact_time)} ms`,
        });
    }
    if (a.steps !== null) {
        dynRows.push({ label: 'Steps', value: formatNumber(a.steps, 0) });
    }
    if (dynRows.length > 0) {
        groups.push({
            key: 'dynamics',
            title:
                a.sport_category === 'running'
                    ? 'Running Dynamics'
                    : 'Cadence & Steps',
            rows: dynRows,
        });
    }

    // 7. Power & Pedaling
    const powerRows: StatRow[] = [];
    if (a.avg_power !== null) {
        powerRows.push({
            label: 'Avg Power',
            value: `${Math.round(a.avg_power)} W`,
        });
    }
    if (a.max_power !== null) {
        powerRows.push({
            label: 'Max Power',
            value: `${Math.round(a.max_power)} W`,
        });
    }
    if (a.normalized_power !== null) {
        powerRows.push({
            label: 'Normalized Power',
            value: `${Math.round(a.normalized_power)} W`,
        });
    }
    if (a.ftp !== null || a.threshold_power !== null) {
        powerRows.push({
            label: a.sport_category === 'running' ? 'Threshold Power' : 'FTP',
            value: `${Math.round((a.ftp ?? a.threshold_power)!)} W`,
        });
    }
    if (a.intensity_factor !== null) {
        powerRows.push({
            label: 'Intensity Factor',
            value: a.intensity_factor.toFixed(2),
        });
    }
    if (a.tss !== null) {
        powerRows.push({ label: 'TSS', value: formatNumber(a.tss, 0) });
    }
    if (a.max_20min_power !== null) {
        powerRows.push({
            label: 'Max 20m Power',
            value: `${Math.round(a.max_20min_power)} W`,
        });
    }
    if (a.total_work !== null && a.total_work > 0) {
        powerRows.push({
            label: 'Total Work',
            value: formatWork(a.total_work),
        });
    }
    if (a.left_balance !== null) {
        const right = Math.max(0, 100 - a.left_balance);
        powerRows.push({
            label: 'L / R Balance',
            value: `${a.left_balance.toFixed(1)}% / ${right.toFixed(1)}%`,
        });
    }
    if (
        a.avg_left_torque_effectiveness !== null ||
        a.avg_right_torque_effectiveness !== null
    ) {
        powerRows.push({
            label: 'Torque Effectiveness (L / R)',
            value: `${a.avg_left_torque_effectiveness !== null ? `${a.avg_left_torque_effectiveness.toFixed(1)}%` : '—'} / ${a.avg_right_torque_effectiveness !== null ? `${a.avg_right_torque_effectiveness.toFixed(1)}%` : '—'}`,
        });
    }
    if (
        a.avg_left_pedal_smoothness !== null ||
        a.avg_right_pedal_smoothness !== null
    ) {
        powerRows.push({
            label: 'Pedal Smoothness (L / R)',
            value: `${a.avg_left_pedal_smoothness !== null ? `${a.avg_left_pedal_smoothness.toFixed(1)}%` : '—'} / ${a.avg_right_pedal_smoothness !== null ? `${a.avg_right_pedal_smoothness.toFixed(1)}%` : '—'}`,
        });
    }
    if (a.wind_enabled !== null) {
        powerRows.push({
            label: 'Wind Data',
            value: a.wind_enabled ? 'Enabled' : 'Disabled',
        });
    }
    if (powerRows.length > 0) {
        groups.push({
            key: 'power',
            title: 'Power',
            rows: powerRows,
        });
    }

    // 8. Trail Dynamics
    const mtbRows: StatRow[] = [];
    const hasTrailTelemetry =
        (a.grit !== null && a.grit > 0) ||
        (a.avg_flow !== null && a.avg_flow > 0) ||
        (a.jump_count !== null && a.jump_count > 0);
    if (a.grit !== null && a.grit > 0) {
        mtbRows.push({ label: 'Garmin Grit', value: formatNumber(a.grit, 1) });
    }
    if (a.avg_flow !== null && a.avg_flow > 0) {
        mtbRows.push({
            label: 'Avg Flow',
            value: formatNumber(a.avg_flow, 1),
            sub: 'Lower is smoother',
        });
    }
    if (a.jump_count !== null && a.jump_count > 0) {
        mtbRows.push({
            label: 'Jumps',
            value: formatNumber(a.jump_count, 0),
        });
    }
    if (
        a.unpaved_pct !== null &&
        (hasTrailTelemetry ? a.unpaved_pct > 0 : a.unpaved_pct >= 15)
    ) {
        mtbRows.push({
            label: 'Unpaved Surface',
            value: `${Math.round(a.unpaved_pct)}%`,
        });
    }
    if (mtbRows.length > 0) {
        groups.push({ key: 'mtb', title: 'Trail Dynamics', rows: mtbRows });
    }

    // 9. Elevation
    const elevRows: StatRow[] = [];
    if (a.elevation_gain !== null) {
        elevRows.push({
            label: 'Total Ascent',
            value: formatElevation(a.elevation_gain),
        });
    }
    if (a.elevation_loss !== null) {
        elevRows.push({
            label: 'Total Descent',
            value: formatElevation(a.elevation_loss),
        });
    }
    if (a.min_elevation !== null) {
        elevRows.push({
            label: 'Min Elev',
            value: formatElevation(a.min_elevation),
        });
    }
    if (a.max_elevation !== null) {
        elevRows.push({
            label: 'Max Elev',
            value: formatElevation(a.max_elevation),
        });
    }
    if (elevRows.length > 0) {
        groups.push({ key: 'elevation', title: 'Elevation', rows: elevRows });
    }

    // 10. Run / Walk Detection
    if (
        a.run_duration_seconds !== null ||
        a.walk_duration_seconds !== null ||
        a.idle_duration_seconds !== null
    ) {
        const rows: StatRow[] = [];
        if (a.run_duration_seconds !== null) {
            rows.push({
                label: 'Run Time',
                value: formatDurationClock(a.run_duration_seconds),
            });
        }
        if (a.walk_duration_seconds !== null) {
            rows.push({
                label: 'Walk Time',
                value: formatDurationClock(a.walk_duration_seconds),
            });
        }
        if (a.idle_duration_seconds !== null && a.idle_duration_seconds > 0) {
            rows.push({
                label: 'Idle Time',
                value: formatDurationClock(a.idle_duration_seconds),
            });
        }
        groups.push({
            key: 'run_walk',
            title: 'Run / Walk Detection',
            rows,
        });
    }

    // 11. Nutrition & Hydration
    const nutRows: StatRow[] = [];
    if (a.bmr_calories !== null) {
        nutRows.push({
            label: 'Resting Calories',
            value: `${formatNumber(a.bmr_calories, 0)} kcal`,
        });
    }
    if (a.calories !== null && a.bmr_calories !== null) {
        nutRows.push({
            label: 'Active Calories',
            value: `${formatNumber(Math.max(0, a.calories - a.bmr_calories), 0)} kcal`,
        });
    }
    if (a.calories !== null) {
        nutRows.push({
            label: 'Total Calories Burned',
            value: `${formatNumber(a.calories, 0)} kcal`,
        });
    }
    if (a.sweat_loss_ml !== null) {
        nutRows.push({
            label: 'Est. Sweat Loss',
            value: formatFluidVolume(a.sweat_loss_ml),
        });
    }
    if (nutRows.length > 0) {
        groups.push({
            key: 'nutrition',
            title: 'Nutrition & Hydration',
            rows: nutRows,
        });
    }

    // 12. Respiration Rate
    if (
        a.avg_respiration_rate !== null ||
        a.min_respiration_rate !== null ||
        a.max_respiration_rate !== null
    ) {
        const rows: StatRow[] = [];
        if (a.avg_respiration_rate !== null) {
            rows.push({
                label: 'Avg Respiration Rate',
                value: `${Math.round(a.avg_respiration_rate)} brpm`,
            });
        }
        if (a.min_respiration_rate !== null) {
            rows.push({
                label: 'Min Respiration Rate',
                value: `${Math.round(a.min_respiration_rate)} brpm`,
            });
        }
        if (a.max_respiration_rate !== null) {
            rows.push({
                label: 'Max Respiration Rate',
                value: `${Math.round(a.max_respiration_rate)} brpm`,
            });
        }
        groups.push({
            key: 'respiration',
            title: 'Respiration Rate',
            rows,
        });
    }

    // 13. Temperature (watch sensor)
    if (
        a.avg_temperature !== null ||
        a.min_temperature !== null ||
        a.max_temperature !== null
    ) {
        const hasAmbientWeather = props.weather !== null;
        const rows: StatRow[] = [];
        if (a.avg_temperature !== null) {
            rows.push({
                label: hasAmbientWeather ? 'Device Temperature' : 'Avg Temp',
                value: formatTemp(a.avg_temperature, 1),
            });
        }
        if (a.min_temperature !== null) {
            rows.push({
                label: hasAmbientWeather ? 'Min Device Temp' : 'Min Temp',
                value: formatTemp(a.min_temperature, 1),
            });
        }
        if (a.max_temperature !== null) {
            rows.push({
                label: hasAmbientWeather ? 'Max Device Temp' : 'Max Temp',
                value: formatTemp(a.max_temperature, 1),
            });
        }
        groups.push({
            key: 'temperature',
            title: hasAmbientWeather ? 'Device Temperature' : 'Temperature',
            rows,
        });
    }

    // 14. Execution Score, Self Evaluation & Body Battery
    const evalRows: StatRow[] = [];
    if (a.execution_score !== null) {
        const targetText =
            a.workout_meta?.name || a.workout_meta?.target_label
                ? [a.workout_meta.name, a.workout_meta.target_label]
                      .filter(Boolean)
                      .join(' · ')
                : null;
        evalRows.push({
            label: 'Execution Score',
            value: `${a.execution_score}% ${formatExecRating(a.execution_score)}`,
            sub: targetText,
        });
    }
    const feelText = formatFeel(a.workout_feel);
    if (feelText) {
        evalRows.push({ label: 'How Did You Feel?', value: feelText });
    }
    const rpeText = formatRpe(a.workout_rpe);
    if (rpeText) {
        evalRows.push({ label: 'Perceived Effort', value: rpeText });
    }
    if (
        a.body_battery_diff !== null ||
        (a.begin_body_battery !== null && a.end_body_battery !== null)
    ) {
        const range =
            a.begin_body_battery !== null && a.end_body_battery !== null
                ? `${a.begin_body_battery} → ${a.end_body_battery}`
                : null;
        evalRows.push({
            label: 'Body Battery Impact',
            value:
                a.body_battery_diff !== null
                    ? `${a.body_battery_diff > 0 ? '+' : ''}${a.body_battery_diff}`
                    : (range ?? '—'),
            sub: a.body_battery_diff !== null ? range : null,
        });
    }
    if (evalRows.length > 0) {
        groups.push({
            key: 'evaluation',
            title: 'Execution, Feel & Body Battery',
            rows: evalRows,
        });
    }

    // 15. Weather & Wind (full-width bottom band so its dense grid never stretches sibling cards)
    if (props.weather !== null) {
        const w = props.weather;
        const weatherRows: StatRow[] = [
            {
                label: 'Condition',
                value: w.condition,
                sub:
                    w.base_condition && w.base_condition !== w.condition
                        ? `Base: ${w.base_condition}`
                        : null,
            },
        ];

        if (w.temperature_c !== null) {
            const tempSub =
                w.min_temperature_c !== null && w.max_temperature_c !== null
                    ? `${formatTemp(w.min_temperature_c, 1)} – ${formatTemp(w.max_temperature_c, 1)}`
                    : null;
            weatherRows.push({
                label: 'Ambient Temperature',
                value: formatTemp(w.temperature_c, 1),
                sub: tempSub,
            });
        }

        if (w.apparent_temperature_c !== null) {
            weatherRows.push({
                label: 'Feels Like',
                value: formatTemp(w.apparent_temperature_c, 1),
            });
        }

        if (w.humidity_pct !== null) {
            weatherRows.push({
                label: 'Relative Humidity',
                value: `${Math.round(w.humidity_pct)}%`,
            });
        }

        if (w.dew_point_c !== null) {
            weatherRows.push({
                label: 'Dew Point',
                value: formatTemp(w.dew_point_c, 1),
            });
        }

        if (w.wind_speed_kmh !== null) {
            weatherRows.push({
                label: 'Wind Speed',
                value: formatSpeedKmh(w.wind_speed_kmh, 1),
            });
        }

        if (w.wind_gusts_kmh !== null) {
            weatherRows.push({
                label: 'Wind Gusts',
                value: formatSpeedKmh(w.wind_gusts_kmh, 1),
            });
        }

        if (
            w.wind_direction_deg !== null ||
            w.wind_direction_compass !== null
        ) {
            const compass =
                w.wind_direction_compass ??
                (w.wind_direction_deg !== null
                    ? formatCompass(w.wind_direction_deg)
                    : '—');
            weatherRows.push({
                label: 'Wind Direction',
                value:
                    w.wind_direction_deg !== null
                        ? `${compass} (${Math.round(w.wind_direction_deg)}°)`
                        : compass,
            });
        }

        if (w.precipitation_mm !== null) {
            weatherRows.push({
                label: 'Precipitation',
                value: formatPrecip(w.precipitation_mm),
            });
        }

        if (w.cloud_cover_pct !== null) {
            weatherRows.push({
                label: 'Cloud Cover',
                value: `${Math.round(w.cloud_cover_pct)}%`,
            });
        }

        if (w.surface_pressure_hpa !== null) {
            weatherRows.push({
                label: 'Surface Pressure',
                value: `${formatNumber(w.surface_pressure_hpa, 1)} hPa`,
            });
        }

        if (
            w.headwind_pct !== null &&
            w.crosswind_pct !== null &&
            w.tailwind_pct !== null
        ) {
            weatherRows.push({
                label: 'Head / Cross / Tail',
                value: `${Math.round(w.headwind_pct)}% / ${Math.round(w.crosswind_pct)}% / ${Math.round(w.tailwind_pct)}%`,
            });
        }

        if (w.avg_effective_wind_kmh !== null) {
            const netLabel =
                w.avg_effective_wind_kmh <= -0.5
                    ? 'Net headwind'
                    : w.avg_effective_wind_kmh >= 0.5
                      ? 'Net tailwind'
                      : 'Neutral';
            weatherRows.push({
                label: 'Avg Effective Wind',
                value: formatSignedWindKmh(w.avg_effective_wind_kmh),
                sub: netLabel,
            });
        }

        const samplingModeLabel =
            w.sampling_mode === 'route'
                ? w.waypoint_count === 1
                    ? 'route waypoint'
                    : 'route waypoints'
                : w.sampling_mode === 'start_point'
                  ? 'start point'
                  : w.sampling_mode.replace(/_/g, ' ');

        weatherRows.push({
            label: 'Data Source',
            value: `${w.waypoint_count} ${samplingModeLabel} · Open-Meteo`,
            sub: w.model_endpoint ? w.model_endpoint.replace(/_/g, ' ') : null,
        });

        groups.push({
            key: 'weather',
            title: 'Weather & Wind',
            layout: 'full',
            rows: weatherRows,
        });
    }

    return groups.map((group) => ({
        ...group,
        layout: group.layout ?? (group.rows.length >= 6 ? 'wide' : 'default'),
    }));
});
</script>

<template>
    <section
        class="grid grid-flow-dense grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
    >
        <div
            v-for="group in statGroups"
            :key="group.key"
            class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            :class="{
                'col-span-full': group.layout === 'full',
                'sm:col-span-2': group.layout === 'wide',
            }"
        >
            <div
                class="mb-3 flex items-center justify-between gap-2 border-b border-zinc-200/70 pb-2 dark:border-zinc-800/70"
            >
                <h3
                    class="font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ group.title }}
                </h3>

                <div
                    v-if="group.toggle === 'hr'"
                    class="inline-flex rounded-md border border-zinc-200/80 bg-[#f7f7f5] p-0.5 text-[10px] dark:border-zinc-800 dark:bg-zinc-900/60"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                        :class="
                            hrUnit === 'bpm'
                                ? 'bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500'
                        "
                        @click="hrUnit = 'bpm'"
                    >
                        bpm
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                        :class="
                            hrUnit === 'pct'
                                ? 'bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500'
                        "
                        @click="hrUnit = 'pct'"
                    >
                        % Max
                    </button>
                </div>

                <div
                    v-else-if="group.toggle === 'pace'"
                    class="inline-flex rounded-md border border-zinc-200/80 bg-[#f7f7f5] p-0.5 text-[10px] dark:border-zinc-800 dark:bg-zinc-900/60"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                        :class="
                            paceUnit === 'pace'
                                ? 'bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500'
                        "
                        @click="paceUnit = 'pace'"
                    >
                        Pace
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                        :class="
                            paceUnit === 'speed'
                                ? 'bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500'
                        "
                        @click="paceUnit = 'speed'"
                    >
                        Speed
                    </button>
                </div>
            </div>

            <dl
                :class="
                    group.layout === 'full'
                        ? 'grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5'
                        : group.layout === 'wide'
                          ? 'grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2'
                          : 'space-y-3'
                "
            >
                <div v-for="row in group.rows" :key="row.label">
                    <dt
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ row.label }}
                    </dt>
                    <dd
                        class="mt-0.5 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ row.value }}
                    </dd>
                    <div
                        v-if="row.sub"
                        class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                    >
                        {{ row.sub }}
                    </div>
                </div>
            </dl>
        </div>
    </section>
</template>
