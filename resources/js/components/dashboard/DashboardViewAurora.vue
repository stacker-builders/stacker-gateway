<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed } from 'vue';
import { useThemeMode } from '@/composables/useThemeMode';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import DashboardPeriodToolbar from '@/components/dashboard/DashboardPeriodToolbar.vue';
import AuroraMetricCard from '@/components/dashboard/AuroraMetricCard.vue';
import AuroraPaymentMethodsCard from '@/components/dashboard/AuroraPaymentMethodsCard.vue';
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

const auroraChartOptions = computed(() => {
    const axisColor = isDark.value ? '#666666' : '#a3a3a3';
    const gridColor = isDark.value ? '#222222' : '#e5e5e5';
    const dataLabelBg = isDark.value ? '#000000' : '#ffffff';

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
            blur: 10,
            opacity: 0.25,
            color: 'var(--color-primary)',
        },
    },
    stroke: {
        curve: 'smooth',
        width: 2.5,
    },
    colors: ['var(--color-primary)'],
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 0.2,
            opacityFrom: 0.4,
            opacityTo: 0.0,
            stops: [0, 100],
        },
    },
    dataLabels: {
        enabled: true,
        formatter: (val, opts) => {
            // Check if it's the highest point (max value) to show the badge like in the mockup
            const seriesData = opts.w.config.series[opts.seriesIndex].data;
            const maxVal = Math.max(...seriesData);
            if (val === maxVal && val > 0) {
                return props.displayCurrency(val);
            }
            return '';
        },
        background: {
            enabled: true,
            foreColor: dataLabelBg,
            borderRadius: 4,
            padding: 4,
            opacity: 1,
            borderWidth: 0,
        },
        style: {
            fontSize: '11px',
            fontWeight: 'bold',
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
        labels: { style: { colors: axisColor, fontSize: '11px' } },
        axisBorder: { show: true, color: gridColor },
        axisTicks: { show: true, color: gridColor },
    },
    yaxis: {
        ...(props.chartOptions.yaxis ?? {}),
        labels: {
            style: { colors: axisColor, fontSize: '11px' },
            formatter: (v) => props.displayCurrency(v)
        },
    },
    tooltip: { theme: isDark.value ? 'dark' : 'light' },
    markers: {
        size: 0,
        hover: { size: 6 },
    },
};
});
</script>

<template>
    <div class="space-y-5">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <!-- Toolbar (Pills) -->
        <DashboardPeriodToolbar
            :period="period"
            :period-options="periodOptions"
            :values-visible="valuesVisible"
            :period-label="periodLabel"
            :hide-values-label="hideValuesLabel"
            :show-values-label="showValuesLabel"
            aurora-style
            @update:period="emit('update:period', $event)"
            @toggle-values="emit('toggle-values')"
        />

        <!-- Linha 1: KPIs Principais (4 Colunas) -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AuroraMetricCard
                :icon="CircleDollarSign"
                :label="labels.totalSales"
                :value="displayCurrency(vendas_totais)"
                :footer="`${labels.pendingSales}: ${displayCurrency(vendas_pendentes)}`"
                variant="hero"
            />
            <AuroraMetricCard
                :icon="ShoppingCart"
                :label="labels.salesCount"
                :value="displayNumber(quantidade_vendas)"
                :footer="`${labels.avgTicket}: ${displayCurrency(ticket_medio)}`"
                variant="default"
            />
            <AuroraMetricCard
                :icon="Package"
                :label="labels.products"
                :value="displayNumber(quantidade_produtos)"
                footer="Total de produtos"
                variant="default"
            />
            <AuroraMetricCard
                :icon="Percent"
                :label="labels.conversionRate"
                :value="conversaoFormatted"
                footer="Taxa de conversão"
                variant="default"
            />
        </div>

        <!-- Linha 2: Pagamentos + Conversão (Esquerda) e Cards Empilhados (Direita) -->
        <div class="grid gap-4 lg:grid-cols-3">
            <AuroraPaymentMethodsCard
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
                <AuroraMetricCard
                    :icon="ShoppingBag"
                    :label="labels.cartAbandonment"
                    :value="abandonoFormatted"
                    :footer="abandonoFooter"
                    href="/relatorios"
                    variant="stacked"
                    tint="purple"
                />
                <AuroraMetricCard
                    :icon="RotateCcw"
                    :label="labels.refunds"
                    :value="displayCurrency(reembolsos_total)"
                    :footer="`${displayNumber(reembolsos_count)} pedido(s)`"
                    href="/vendas/reembolsos"
                    variant="stacked"
                    tint="orange"
                />
                <AuroraMetricCard
                    :icon="Package"
                    :label="labels.products"
                    :value="displayNumber(quantidade_produtos)"
                    footer="Total de produtos"
                    href="/produtos"
                    variant="stacked"
                    tint="blue"
                />
            </div>
        </div>

        <!-- Linha 3: Gráfico Desempenho de Vendas -->
        <div class="aurora-card p-5 pb-0">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="aurora-fg-muted flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest">
                    <TrendingUp class="h-3.5 w-3.5 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.salesPerformance }}
                </h2>
                <div class="aurora-chart-select flex cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 text-xs aurora-surface-hover">
                    Gráfico de linhas
                    <ChevronDown class="h-3 w-3" />
                </div>
            </div>
            <div class="min-h-[300px]">
                    <VueApexCharts
                        v-if="grafico_vendas.length"
                        :key="isDark ? 'aurora-chart-dark' : 'aurora-chart-light'"
                        type="area"
                        height="300"
                        :options="auroraChartOptions"
                        :series="chartSeries"
                    />
                <p v-else class="aurora-fg-muted flex h-[300px] items-center justify-center text-sm">
                    {{ labels.noSalesData }}
                </p>
            </div>
        </div>
    </div>
</template>
