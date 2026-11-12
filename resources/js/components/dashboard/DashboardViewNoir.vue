<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import {
    CircleDollarSign,
    Code2,
    Clock3,
    CreditCard,
    Package,
    Percent,
    RotateCcw,
    ShoppingBag,
    TrendingUp,
} from 'lucide-vue-next';
import { useThemeMode } from '@/composables/useThemeMode';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import DashboardPeriodToolbar from '@/components/dashboard/DashboardPeriodToolbar.vue';
import DashboardCustomPeriodForm from '@/components/dashboard/DashboardCustomPeriodForm.vue';

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
    vendas_api: { type: Number, default: 0 },
    quantidade_vendas_api: { type: Number, default: 0 },
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

const conversaoFormatted = computed(() => (props.valuesVisible ? `${props.taxa_conversao}%` : '—'));
const abandonoFormatted = computed(() => (props.valuesVisible ? `${props.taxa_abandono_compras}%` : '—'));
const conversionWidth = computed(() => {
    const value = Number(props.taxa_conversao) || 0;
    return `${Math.min(100, Math.max(0, value))}%`;
});

const paymentTotal = computed(() =>
    props.formas_pagamento.reduce((sum, fp) => sum + (Number(fp.total) || 0), 0),
);

function paymentShare(fp) {
    if (!paymentTotal.value) return 0;
    return Math.round((Number(fp.total) / paymentTotal.value) * 100);
}

const noirChartOptions = computed(() => {
    const axisColor = isDark.value ? '#71717a' : '#a1a1aa';
    const gridColor = isDark.value ? '#262626' : '#e4e4e7';

    return {
        ...props.chartOptions,
        chart: {
            ...(props.chartOptions.chart ?? {}),
            background: 'transparent',
            foreColor: axisColor,
            toolbar: { show: false },
        },
        colors: ['var(--color-primary)'],
        stroke: { curve: 'smooth', width: 2.5 },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.15,
                opacityFrom: 0.28,
                opacityTo: 0,
                stops: [0, 100],
            },
        },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
        },
        xaxis: {
            ...(props.chartOptions.xaxis ?? {}),
            labels: { style: { colors: axisColor, fontSize: '11px' } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            ...(props.chartOptions.yaxis ?? {}),
            labels: {
                style: { colors: axisColor, fontSize: '11px' },
                formatter: (v) => props.displayCurrency(v),
            },
        },
        tooltip: { theme: isDark.value ? 'dark' : 'light' },
        dataLabels: { enabled: false },
    };
});
</script>

<template>
    <div class="space-y-4">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <DashboardPeriodToolbar
            :period="period"
            :period-options="periodOptions"
            :values-visible="valuesVisible"
            :period-label="periodLabel"
            :hide-values-label="hideValuesLabel"
            :show-values-label="showValuesLabel"
            shell="noir"
            @update:period="emit('update:period', $event)"
            @toggle-values="emit('toggle-values')"
        />

        <DashboardCustomPeriodForm />

        <section class="noir-card noir-card-hero p-5">
            <p class="noir-card-label text-[11px] font-semibold uppercase tracking-widest">
                {{ labels.totalSales }}
            </p>
            <p class="noir-hero-amount mt-3">
                {{ displayCurrency(vendas_totais) }}
            </p>
            <p class="noir-card-footer mt-3 text-sm">
                {{ displayNumber(quantidade_vendas) }} {{ labels.salesCount }}
                <span class="mx-1.5 opacity-40">·</span>
                {{ labels.avgTicket }}: {{ displayCurrency(ticket_medio) }}
            </p>
        </section>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="noir-card p-4">
                <div class="noir-card-label flex items-center gap-2 text-xs font-medium">
                    <Clock3 class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.pendingSales }}
                </div>
                <p class="noir-card-value mt-3 text-xl font-semibold tracking-tight">
                    {{ displayCurrency(vendas_pendentes) }}
                </p>
            </article>
            <article class="noir-card p-4">
                <div class="noir-card-label flex items-center gap-2 text-xs font-medium">
                    <Code2 class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.apiSales }}
                </div>
                <p class="noir-card-value mt-3 text-xl font-semibold tracking-tight">
                    {{ displayCurrency(vendas_api) }}
                </p>
                <p class="noir-card-footer mt-1 text-xs">
                    {{ displayNumber(quantidade_vendas_api) }} {{ labels.ordersCount }}
                </p>
            </article>
            <Link href="/produtos" class="noir-card noir-stacked-card--link block p-4">
                <div class="noir-card-label flex items-center gap-2 text-xs font-medium">
                    <Package class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.products }}
                </div>
                <p class="noir-card-value mt-3 text-xl font-semibold tracking-tight">
                    {{ displayNumber(quantidade_produtos) }}
                </p>
            </Link>
            <article class="noir-card p-4">
                <div class="noir-card-label flex items-center gap-2 text-xs font-medium">
                    <Percent class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.conversionRate }}
                </div>
                <p class="noir-card-value mt-3 text-xl font-semibold tracking-tight">
                    {{ conversaoFormatted }}
                </p>
            </article>
        </div>

        <div class="grid gap-3 lg:grid-cols-5">
            <section class="noir-card p-5 lg:col-span-3">
                <h2 class="noir-fg-muted mb-4 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-widest">
                    <TrendingUp class="h-3.5 w-3.5 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.salesPerformance }}
                </h2>
                <div class="min-h-[280px]">
                    <VueApexCharts
                        v-if="grafico_vendas.length"
                        :key="isDark ? 'noir-chart-dark' : 'noir-chart-light'"
                        type="area"
                        height="280"
                        :options="noirChartOptions"
                        :series="chartSeries"
                    />
                    <p v-else class="noir-fg-muted flex h-[280px] items-center justify-center text-sm">
                        {{ labels.noSalesData }}
                    </p>
                </div>
            </section>

            <section class="noir-card flex flex-col p-5 lg:col-span-2">
                <h2 class="noir-fg-muted flex items-center gap-2 text-[11px] font-semibold uppercase tracking-widest">
                    <CircleDollarSign class="h-3.5 w-3.5 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.conversionRate }}
                </h2>
                <p class="noir-hero-amount mt-4 text-4xl">
                    {{ conversaoFormatted }}
                </p>
                <p class="noir-card-footer mt-2 text-sm">
                    {{ displayNumber(quantidade_vendas) }} {{ labels.salesCount }}
                </p>
                <div class="noir-progress-track mt-6">
                    <div class="noir-progress-fill" :style="{ width: valuesVisible ? conversionWidth : '0%' }" />
                </div>
            </section>
        </div>

        <div class="grid gap-3 lg:grid-cols-3">
            <section class="noir-card p-5">
                <h2 class="noir-fg-muted mb-4 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-widest">
                    <CreditCard class="h-3.5 w-3.5 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.paymentMethods }}
                </h2>
                <ul class="space-y-4">
                    <li v-for="fp in formas_pagamento" :key="fp.metodo">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="noir-fg text-sm">{{ fp.label }}</span>
                            <span class="noir-card-value text-sm font-semibold">
                                {{ displayCurrency(fp.total) }}
                                <span class="noir-fg-muted ml-1 text-xs font-normal">({{ displayNumber(fp.quantidade) }})</span>
                            </span>
                        </div>
                        <div class="noir-progress-track">
                            <div class="noir-progress-fill" :style="{ width: `${paymentShare(fp)}%` }" />
                        </div>
                    </li>
                    <li v-if="!formas_pagamento.length" class="noir-fg-muted py-6 text-center text-sm">
                        {{ labels.noPayments }}
                    </li>
                </ul>
            </section>

            <Link href="/relatorios" class="noir-card noir-stacked-card--link block p-5">
                <div class="noir-card-label flex items-center gap-2 text-xs font-medium">
                    <ShoppingBag class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.cartAbandonment }}
                </div>
                <p class="noir-card-value mt-4 text-2xl font-semibold tracking-tight">
                    {{ abandonoFormatted }}
                </p>
                <p class="noir-card-footer mt-2 text-sm">
                    {{ displayNumber(abandono_carrinho) }} {{ labels.abandonedCount }}
                    <span class="mx-1 opacity-40">·</span>
                    {{ displayNumber(compras_periodo) }} {{ labels.purchasesCount }}
                </p>
            </Link>

            <Link href="/vendas/reembolsos" class="noir-card noir-stacked-card--link block p-5">
                <div class="noir-card-label flex items-center gap-2 text-xs font-medium">
                    <RotateCcw class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.refunds }}
                </div>
                <p class="noir-card-value mt-4 text-2xl font-semibold tracking-tight">
                    {{ displayCurrency(reembolsos_total) }}
                </p>
                <p class="noir-card-footer mt-2 text-sm">
                    {{ displayNumber(reembolsos_count) }} {{ labels.ordersCount }}
                </p>
            </Link>
        </div>
    </div>
</template>
