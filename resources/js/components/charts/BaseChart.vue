<script setup lang="ts">
import type { ECharts, EChartsOption } from 'echarts';
import * as echarts from 'echarts';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useChartTheme } from '@/composables/useChartTheme';

type AxisPointerEvent = {
    axesInfo?: Array<{
        value?: number;
    }>;
};

export type ChartPointClickPayload = {
    dataIndex: number;
    name: string;
    seriesName?: string;
};

const props = withDefaults(
    defineProps<{
        option: EChartsOption;
        /** Partial option merged on top of `option` (e.g. highlights) without a full re-render. */
        overlayOption?: EChartsOption | null;
        height?: number | string;
        group?: string;
    }>(),
    {
        overlayOption: null,
        height: 240,
        group: undefined,
    },
);

const emit = defineEmits<{
    hoverIndex: [index: number | null];
    pointClick: [payload: ChartPointClickPayload];
}>();

const containerRef = ref<HTMLElement | null>(null);
let chart: ECharts | null = null;
let resizeObserver: ResizeObserver | null = null;

const {
    isDark,
    textColor,
    tooltipBg,
    tooltipBorder,
    tooltipText,
    fontFamilyMono,
} = useChartTheme();

const containerStyle = computed(() => ({
    height:
        typeof props.height === 'number' ? `${props.height}px` : props.height,
}));

const mergedOption = computed<EChartsOption>(() => {
    const opt = props.option;
    const baseTooltip = {
        backgroundColor: tooltipBg.value,
        borderColor: tooltipBorder.value,
        borderWidth: 1,
        textStyle: {
            color: tooltipText.value,
            fontFamily: fontFamilyMono,
            fontSize: 12,
        },
    };

    let tooltip = opt.tooltip;

    if (tooltip && !Array.isArray(tooltip)) {
        tooltip = {
            ...baseTooltip,
            ...tooltip,
            textStyle: {
                ...baseTooltip.textStyle,
                ...tooltip.textStyle,
            },
        };
    }

    return {
        backgroundColor: 'transparent',
        textStyle: {
            color: textColor.value,
            fontFamily: fontFamilyMono,
        },
        ...opt,
        ...(tooltip !== undefined ? { tooltip } : {}),
    };
});

function renderChart(): void {
    if (!chart) {
        return;
    }

    chart.setOption(mergedOption.value, { notMerge: true });

    if (props.overlayOption) {
        chart.setOption(props.overlayOption);
    }
}

onMounted(() => {
    if (!containerRef.value) {
        return;
    }

    chart = echarts.init(containerRef.value, undefined, {
        renderer: 'canvas',
    });

    if (props.group) {
        chart.group = props.group;
        echarts.connect(props.group);
    }

    chart.on('updateAxisPointer', (event: unknown) => {
        const axesInfo = (event as AxisPointerEvent)?.axesInfo;
        const firstValue = axesInfo?.[0]?.value;

        if (typeof firstValue === 'number' && Number.isFinite(firstValue)) {
            emit('hoverIndex', firstValue);
        }
    });

    chart.on('globalout', () => {
        emit('hoverIndex', null);
    });

    chart.on('click', (params) => {
        if (typeof params.dataIndex === 'number') {
            emit('pointClick', {
                dataIndex: params.dataIndex,
                name: typeof params.name === 'string' ? params.name : '',
                seriesName:
                    typeof params.seriesName === 'string'
                        ? params.seriesName
                        : undefined,
            });
        }
    });

    renderChart();

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            chart?.resize();
        });
        resizeObserver.observe(containerRef.value);
    }
});

watch([mergedOption, isDark], () => {
    renderChart();
});

watch(
    () => props.overlayOption,
    (overlay, previous) => {
        if (!chart) {
            return;
        }

        if (overlay) {
            chart.setOption(overlay, { lazyUpdate: true });
        } else if (previous) {
            renderChart();
        }
    },
);

watch(
    () => props.group,
    (group) => {
        if (!chart) {
            return;
        }

        chart.group = group ?? '';

        if (group) {
            echarts.connect(group);
        }
    },
);

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
    chart?.dispose();
    chart = null;
});
</script>

<template>
    <div ref="containerRef" class="w-full min-w-0" :style="containerStyle" />
</template>
