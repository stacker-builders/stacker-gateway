<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed } from 'vue';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import KawaiiPeriodToolbar from '@/components/kawaii/KawaiiPeriodToolbar.vue';
import KawaiiMetricCard from '@/components/kawaii/KawaiiMetricCard.vue';
import KawaiiPaymentMethodsCard from '@/components/kawaii/KawaiiPaymentMethodsCard.vue';
import { useThemeMode } from '@/composables/useThemeMode';
import {
    CircleDollarSign,
    ShoppingCart,
    Package,
    Percent,
    ShoppingBag,
    RotateCcw,
    TrendingUp,
    ChevronDown,
} from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, required: true },
    hasAchievementsProgress: { type: Boolean, default: false },
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
    vendas_totais: { type: Number, default: 0 },
    vendas_pendentes: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    abandono_carrinho: { type: Number, default: 0 },
    taxa_abandono_compras: { type: Number, default: 0 },
    compras_periodo: { type: Number, default: 0 },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
    quantidade_produtos: { type: Number, default: 0 },
    grafico_vendas: { type: Array, default: () => [] },
    chartOptions: { type: Object, required: true },
    chartSeries: { type: Array, required: true },
    labels: { type: Object, required: true },
    displayCurrency: { type: Function, required: true },
    displayNumber: { type: Function, required: true },
});

const emit = defineEmits(['update:period', 'toggle-values']);

const { isDark } = useThemeMode();

const conversaoFormatted = computed(() =>
    props.valuesVisible ? `${props.taxa_conversao}%` : '—'
);

const abandonoFormatted = computed(() =>
    props.valuesVisible ? `${props.taxa_abandono_compras}%` : '—'
);

const abandonoFooter = computed(() =>
    `${props.valuesVisible ? props.abandono_carrinho : '—'} ${props.labels.abandonedCount} · ${props.valuesVisible ? props.compras_periodo : '—'} ${props.labels.purchasesCount}`
);

const kawaiiChartOptions = computed(() => {
    const axisColor = isDark.value ? '#b8b0cc' : '#9b94a8';
    const gridColor = isDark.value ? '#3d3654' : '#ebe9f2';

    return {
        ...props.chartOptions,
        chart: {
            ...(props.chartOptions.chart ?? {}),
            background: 'transparent',
            foreColor: axisColor,
            toolbar: { show: false },
            dropShadow: {
                enabled: true,
                top: 6,
                left: 0,
                blur: 12,
                opacity: 0.2,
                color: 'var(--color-primary)',
            },
        },
        stroke: { curve: 'smooth', width: 3 },
        colors: ['var(--color-primary)'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.2,
                opacityFrom: 0.45,
                opacityTo: 0.05,
                stops: [0, 100],
            },
        },
        dataLabels: {
            enabled: true,
            formatter: (val, opts) => {
                const seriesData = opts.w.config.series[opts.seriesIndex].data;
                const maxVal = Math.max(...seriesData);
                if (val === maxVal && val > 0) {
                    return props.displayCurrency(val);
                }
                return '';
            },
            background: {
                enabled: true,
                foreColor: isDark.value ? '#2a2538' : '#ffffff',
                borderRadius: 8,
                padding: 6,
                opacity: 1,
                borderWidth: 0,
            },
            style: {
                fontSize: '11px',
                fontWeight: '800',
                colors: ['var(--color-primary)'],
            },
            offsetY: -10,
        },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 0,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: false } },
        },
        xaxis: {
            ...(props.chartOptions.xaxis ?? {}),
            labels: { style: { colors: axisColor, fontSize: '11px', fontWeight: 600 } },
            axisBorder: { show: true, color: gridColor },
            axisTicks: { show: true, color: gridColor },
        },
        yaxis: {
            ...(props.chartOptions.yaxis ?? {}),
            labels: {
                style: { colors: axisColor, fontSize: '11px', fontWeight: 600 },
                formatter: (v) => props.displayCurrency(v),
            },
        },
        tooltip: { theme: isDark.value ? 'dark' : 'light' },
        markers: { size: 0, hover: { size: 6 } },
    };
});
</script>

<template>
    <div class="space-y-5">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <KawaiiPeriodToolbar
            :period="period"
            :period-options="periodOptions"
            :values-visible="valuesVisible"
            :period-label="periodLabel"
            :hide-values-label="hideValuesLabel"
            :show-values-label="showValuesLabel"
            @update:period="emit('update:period', $event)"
            @toggle-values="emit('toggle-values')"
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KawaiiMetricCard
                :icon="CircleDollarSign"
                :label="labels.totalSales"
                :value="displayCurrency(vendas_totais)"
                variant="hero"
                tint="green"
            />
            <KawaiiMetricCard
                :icon="ShoppingCart"
                :label="labels.salesCount"
                :value="displayNumber(quantidade_vendas)"
                tint="purple"
            />
            <KawaiiMetricCard
                :icon="Package"
                :label="labels.products"
                :value="displayNumber(quantidade_produtos)"
                tint="blue"
            />
            <KawaiiMetricCard
                :icon="Percent"
                :label="labels.conversionRate"
                :value="conversaoFormatted"
                tint="yellow"
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <KawaiiPaymentMethodsCard
                :formas_pagamento="formas_pagamento"
                :taxa_conversao="taxa_conversao"
                :values-visible="valuesVisible"
                :conversion-label="labels.conversionRate"
                :payment-methods-label="labels.paymentMethods"
                :no-payments-label="labels.noPayments"
                :grafico_vendas="grafico_vendas"
                :display-currency="displayCurrency"
                :display-number="displayNumber"
            />

            <div class="flex flex-col gap-4">
                <KawaiiMetricCard
                    :icon="ShoppingBag"
                    :label="labels.cartAbandonment"
                    :value="abandonoFormatted"
                    :footer="abandonoFooter"
                    href="/relatorios"
                    variant="stacked"
                    tint="pink"
                />
                <KawaiiMetricCard
                    :icon="RotateCcw"
                    :label="labels.refunds"
                    :value="displayCurrency(reembolsos_total)"
                    href="/vendas/reembolsos"
                    variant="stacked"
                    tint="orange"
                />
                <KawaiiMetricCard
                    :icon="Package"
                    :label="labels.products"
                    :value="displayNumber(quantidade_produtos)"
                    href="/produtos"
                    variant="stacked"
                    tint="sky"
                />
            </div>
        </div>

        <div class="kawaii-card p-5 pb-0">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="kawaii-fg-muted flex items-center gap-2 text-[10px] font-extrabold uppercase tracking-widest">
                    <TrendingUp class="h-3.5 w-3.5 text-[var(--kawaii-accent-text)]" aria-hidden="true" />
                    {{ labels.salesPerformance }}
                </h2>
                <div class="kawaii-chart-select flex cursor-pointer items-center gap-2 px-3 py-1.5">
                    Gráfico de linhas
                    <ChevronDown class="h-3 w-3" aria-hidden="true" />
                </div>
            </div>
            <div class="min-h-[300px]">
                <VueApexCharts
                    v-if="grafico_vendas.length"
                    :key="isDark ? 'kawaii-chart-dark' : 'kawaii-chart-light'"
                    type="area"
                    height="300"
                    :options="kawaiiChartOptions"
                    :series="chartSeries"
                />
                <p v-else class="kawaii-fg-muted flex h-[300px] items-center justify-center text-sm font-semibold">
                    {{ labels.noSalesData }}
                </p>
            </div>
        </div>
    </div>
</template>
